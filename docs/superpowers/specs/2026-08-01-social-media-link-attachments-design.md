# Social Media Link Attachments — Design

## Problem

When a user replies to the bot's own message, or a message that @mentions the bot, and that
message contains a link to a Twitter/X, Instagram, or TikTok post, the bot should fetch the
post's visual media (photo/video) and reply with it attached — instead of, or in addition to,
its usual text-only reply.

## Trigger & Scope

Reuses the same gate `GenericmessageCommand::shouldReply()` already uses: the incoming message
either @mentions the bot or is a Telegram reply to a message sent by the bot. No new trigger
mechanism, no per-chat opt-in/opt-out — this behaves the same in every chat the bot already
responds in, consistent with how the existing AI-reply flow works.

Within `GenericmessageCommand::handle()`, before building the OpenAI request, the message
text/caption is scanned for the **first** supported post-level link. Only permalink-style URLs
match — profile/feed/search URLs are intentionally excluded (downloading an entire profile's
timeline via yt-dlp would be unbounded and unsafe):

| Platform  | Matches | Excludes |
|-----------|---------|----------|
| Twitter/X | `(twitter\.com\|x\.com)/[^/]+/status/\d+` | `twitter.com/username` (profile) |
| Instagram | `instagram\.com/(p\|reel\|tv)/[A-Za-z0-9_-]+` | `instagram.com/username` (profile) |
| TikTok    | `tiktok\.com/@[^/]+/video/\d+`, `vm.tiktok.com/*`, `vt.tiktok.com/*` (short links, resolved by yt-dlp) | `tiktok.com/@username` (profile) |

If no supported link is found, control falls through unchanged to the existing OpenAI text-reply
flow. Only the first matched link in a message is processed; additional links are ignored (YAGNI
— easy to extend to multiple later if it comes up).

## Extraction

New `App\Services\SocialMediaDownloadService`:

- Detects the platform + validates the URL against the table above (`SocialMediaPlatform` enum:
  `Twitter`, `Instagram`, `TikTok`).
- Shells out to the `yt-dlp` binary via Laravel's `Process` facade (`Illuminate\Support\Facades\Process`,
  bundled since Laravel 10 — chosen over raw `symfony/process` because it has first-class fakes
  for testing, so no live network calls are needed in the test suite).
- Each invocation gets a fresh scratch directory: `storage/app/tmp/social/{uuid}/`.
- Command: `yt-dlp --print-json --no-warnings -o "<dir>/%(id)s_%(playlist_index)s.%(ext)s" --format "best[filesize<48M]/best" <url>`
  - The output template numbers each entry, which naturally handles both single-media posts and
    multi-media posts (Twitter multi-photo tweets, Instagram carousels — yt-dlp models these as
    playlist entries sharing one URL).
  - `--print-json` emits one JSON object (NDJSON) per downloaded entry with `title`, `description`,
    `thumbnail`, `ext`, and `filesize` — this is both our metadata source and our success signal.
  - Format filter prefers results under Telegram's 50MB bot-upload cap; still validated after
    download since the filter is a preference, not a guarantee.
- A hard timeout (45s) is set on the process so a slow/stuck download can't block the RoadRunner
  worker indefinitely (no queue worker exists in this project — everything runs inline on the
  webhook request, matching how the existing OpenAI call is already synchronous).
- After the process completes, the scratch directory is globbed for produced files (source of
  truth for "what got downloaded"). Each file over Telegram's 50MB hard upload cap is dropped
  individually (the `--format` filter is a preference, not a guarantee — a post can still yield an
  oversized file when no smaller format exists). The remaining files are capped at **10** items
  (Telegram's own `sendMediaGroup` limit); any excess beyond 10 is discarded.
- Returns a `SocialMediaResource[]` DTO list (`type: photo|video`, absolute file `path`, `title`,
  `description`, `thumbnailPath`) — or throws a `SocialMediaDownloadException` (nonzero exit,
  timeout, or zero files remaining after the per-item size filter) that the caller handles as a
  failure.
- `thumbnailPath`: for video entries, downloaded separately via the `thumbnail` URL in the JSON
  metadata (reusing the same download-to-temp-file pattern). Pure-photo entries don't reliably
  carry a distinct `thumbnail` field — in that case `thumbnailPath` is simply the photo's own
  downloaded `path`, so the AI vision step always has an image to work with without a separate
  fetch.

## Sending to Telegram

The media reply is a genuine Telegram-threaded reply: `reply_to_message_id` is set to the
original message containing the link. (Note: this is new behavior for this feature specifically —
`BaseCommand::sendText()` today does **not** thread replies; it is left unchanged for the existing
AI text-reply path.)

- Single resource → `Request::sendPhoto()` / `Request::sendVideo()` with the file uploaded via
  `Request::encodeFile()` (multipart) and `caption` set to the AI-generated text (see below).
- Multiple resources → `Request::sendMediaGroup()` with an array of `InputMediaPhoto`/`InputMediaVideo`,
  each referencing its file via `attach://` + multipart upload; the caption is set only on the
  first item (Telegram renders it as the album's caption).

Every file in the scratch directory (media + thumbnail) is deleted in a `finally` block
immediately after the Telegram API call returns — success or failure — so nothing persists beyond
the request's lifetime. This satisfies "don't store it locally" in the only way the underlying
Telegram library allows (`Request::encodeFile()` requires a real filesystem path; there is no
pure in-memory upload path available), while guaranteeing no residual files.

## AI Integration (success path)

The bot doesn't just attach raw media — it comments on it, in character:

- The extracted `title`/`description` (from the first resource, when there are several) becomes
  quoted context, following the same pattern `GenericmessageCommand::buildRequest()` already uses
  for reply-to-text/caption context.
- The first resource's `thumbnailPath` is fed through the **existing** vision pipeline
  (`extractImageDataUri`-equivalent handling → `OpenAIService::generateResponse()`'s
  `$imageDataUri` parameter), reusing the same code path already used for photos/stickers rather
  than building a parallel one. Only one representative image is sent to vision regardless of how
  many resources were extracted.
- The resulting AI text becomes the caption on the outgoing media message (single resource) or the
  first item's caption (media group) — one outgoing message per link, not two.

## Failure Handling

Any `SocialMediaDownloadException` (private/deleted post, unsupported/region-locked, all
candidate formats over the 48MB filter with nothing else available, yt-dlp process error/timeout)
does **not** silently fall back to a plain text reply and does **not** silently do nothing. It
calls `OpenAIService::generateResponse()` with a synthetic user-turn message describing the
failure (e.g. "Не вдалося завантажити медіа за посиланням, причина: <reason>. Відповідай у
своєму стилі.") so the existing per-chat prompt/persona produces an in-character reply about the
failure, same as any other message — no new "error message" string templates needed.

## Infra (Dockerfile)

Two throwaway builder stages, copied into the final runtime image via `COPY --from` so neither
build tooling nor download-time artifacts land in the shipped image:

```dockerfile
FROM mwader/static-ffmpeg:<pinned-tag> AS ffmpeg

FROM debian:bookworm-slim AS ytdlp-fetch
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates
ARG YTDLP_VERSION=<pinned-release-tag>
RUN curl -fL -o /usr/local/bin/yt-dlp \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && curl -fL -o /tmp/SHA2-256SUMS \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/SHA2-256SUMS" \
    && grep "yt-dlp_linux$" /tmp/SHA2-256SUMS | sha256sum -c - \
    && chmod +x /usr/local/bin/yt-dlp
```

Final stage adds:

```dockerfile
COPY --from=ffmpeg /ffmpeg /ffprobe /usr/local/bin/
COPY --from=ytdlp-fetch /usr/local/bin/yt-dlp /usr/local/bin/yt-dlp
```

Both the `mwader/static-ffmpeg` tag and `YTDLP_VERSION` are pinned explicitly (not `latest`),
matching this Dockerfile's existing convention (`roadrunner:2.8.7`, `php:8.4.3-cli`). No new
Composer dependencies are needed — `Process` ships with `laravel/framework` (already v10.48, which
includes it), and `symfony/process` is already a transitive dependency.

`storage/app/tmp/` needs to exist and be writable at runtime (add a `.gitignore`-covered directory
or ensure it's created on demand by the service before first use).

## Testing

- Unit tests for `SocialMediaPlatform`/link-matching: valid permalinks per platform match and
  extract the right platform; profile/feed URLs and garbage input don't match.
- `SocialMediaDownloadService` tests use `Process::fake()` to simulate: single photo, single
  video, multi-item carousel (multiple numbered output files + multiple JSON lines), nonzero
  exit code, timeout, and "all produced files exceed the size cap" — asserting the right DTOs (or
  exception) come back and that scratch files created during the fake are still cleaned up.
- `GenericmessageCommand` integration-level tests (mocking `SocialMediaDownloadService` and
  `OpenAIService`) covering: link found + download succeeds → media sent as threaded reply with
  AI caption, correct `InputMedia` types for photo vs video vs multi-item; link found + download
  fails → `OpenAIService` invoked with the failure context, text-only reply sent; no link found →
  existing behavior unchanged (regression guard).
