# Social Media Link Attachments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user replies to the bot (or replies to a message that @mentions the bot) with a message containing a Twitter/X, Instagram, or TikTok post link, the bot downloads the post's photo/video media via `yt-dlp` and replies with the attachment(s) as a threaded Telegram reply, captioned by an in-character AI response.

**Architecture:** A new `App\Services\SocialMedia` namespace holds platform detection (`SocialMediaLink`), a `yt-dlp`-shelling download service (`SocialMediaDownloadService`) that writes to an ephemeral per-request scratch directory, and a pure text-building helper (`SocialMediaContextBuilder`) that feeds the existing `OpenAIService` pipeline. `GenericmessageCommand::handle()` detects the link before its existing OpenAI flow and branches into the new path; on success it sends `Request::sendPhoto`/`sendVideo`/`sendMediaGroup` with local file paths (which `longman/telegram-bot` uploads via multipart automatically) and deletes the scratch directory immediately after; on failure it asks `OpenAIService` for an in-character reply describing the failure instead of silently doing nothing.

**Tech Stack:** Laravel 10 / PHP 8.4, `Illuminate\Support\Facades\Process` (shells out to the `yt-dlp` binary, array-form commands only), `longman/telegram-bot` (`Request::sendPhoto`/`sendVideo`/`sendMediaGroup`, `InputMediaPhoto`/`InputMediaVideo`), GD (already installed), Docker multi-stage build adding `mwader/static-ffmpeg:8.1.2` and a pinned `yt-dlp` release binary (`2026.07.04`).

## Global Constraints

- Reuses `GenericmessageCommand::shouldReply()` unchanged — no new trigger mechanism, no per-chat opt-in/opt-out.
- Only the **first** supported link in a message is processed, chosen by earliest textual position in the string (not by platform priority) — additional links in the same message are ignored.
- Only permalink-style URLs match: `(twitter\.com|x\.com)/[^/]+/status/\d+`, `instagram\.com/(p|reel|tv)/[A-Za-z0-9_-]+`, `tiktok\.com/@[^/]+/video/\d+` or `v[mt]\.tiktok\.com/*` — profile/feed/search URLs are intentionally excluded.
- All downloaded media lives only in `storage/app/tmp/social/{uuid}/`, an ephemeral per-download-attempt directory, deleted in a `finally` block immediately after the Telegram send call (success path) or immediately on any failure inside the download service itself — nothing persists beyond the request's lifetime.
- The `yt-dlp` process has a hard 45-second timeout (no queue worker exists in this project — this runs inline on the synchronous webhook request, same as the existing OpenAI call).
- `yt-dlp --format` preference is `best[filesize<48M]/best`; after download, each produced file is re-checked against a hard 50MB (`50 * 1024 * 1024` bytes) cap — oversized files are dropped individually, not failing the whole batch. If zero resources remain after filtering, the download is treated as a failure.
- At most 10 resources are kept per message (Telegram's `sendMediaGroup` limit) — any excess is discarded.
- A failed download never silently falls back to plain text and never silently does nothing — it always produces an in-character AI reply via `OpenAIService::generateResponse()` describing the failure.
- Dockerfile changes use a multi-stage build with pinned versions only (no `latest` tags), matching the existing convention (`roadrunner:2.8.7`, `php:8.4.3-cli`).
- No new Composer dependencies — `Illuminate\Support\Facades\Process` ships with `laravel/framework` (already installed).
- All shell commands to `yt-dlp` use array-form (`Process::run([...])`), never string interpolation, since the URL comes from user-controlled Telegram message text.
- Follow `php-guidelines-from-spatie`: `final readonly` DTOs with promoted constructors, imported classnames in docblocks, early returns, no `else`, always import namespaces (never inline `\Exception`-style FQCNs).

---

### Task 1: `SocialMediaPlatform` enum + `SocialMediaLink` value object

**Files:**
- Create: `app/Services/SocialMedia/SocialMediaPlatform.php`
- Create: `app/Services/SocialMedia/SocialMediaLink.php`
- Test: `tests/Unit/Services/SocialMedia/SocialMediaLinkTest.php`

**Interfaces:**
- Produces: `SocialMediaPlatform` enum (cases `Twitter`, `Instagram`, `TikTok`); `SocialMediaLink::findIn(string $text): ?self` returning a readonly object with public `platform: SocialMediaPlatform` and `url: string` properties; public constructor `new SocialMediaLink(SocialMediaPlatform $platform, string $url)`.

- [ ] **Step 1: Write the enum**

```php
<?php

namespace App\Services\SocialMedia;

enum SocialMediaPlatform: string
{
    case Twitter = 'twitter';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
}
```

- [ ] **Step 2: Write the failing test for the value object**

```php
<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaPlatform;
use PHPUnit\Framework\TestCase;

class SocialMediaLinkTest extends TestCase
{
    public function test_matches_twitter_status_url(): void
    {
        $link = SocialMediaLink::findIn('check this out https://twitter.com/someuser/status/1234567890');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Twitter, $link->platform);
        $this->assertSame('https://twitter.com/someuser/status/1234567890', $link->url);
    }

    public function test_matches_x_status_url(): void
    {
        $link = SocialMediaLink::findIn('https://x.com/someuser/status/1234567890');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Twitter, $link->platform);
    }

    public function test_matches_instagram_post_url(): void
    {
        $link = SocialMediaLink::findIn('look https://www.instagram.com/p/Cabc123XYZ/ nice');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Instagram, $link->platform);
    }

    public function test_matches_instagram_reel_url(): void
    {
        $link = SocialMediaLink::findIn('https://instagram.com/reel/Cabc123XYZ/');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Instagram, $link->platform);
    }

    public function test_matches_tiktok_video_url(): void
    {
        $link = SocialMediaLink::findIn('https://www.tiktok.com/@someuser/video/1234567890123456789');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }

    public function test_matches_tiktok_short_url(): void
    {
        $link = SocialMediaLink::findIn('https://vm.tiktok.com/ABC123/');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }

    public function test_ignores_twitter_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://twitter.com/someuser'));
    }

    public function test_ignores_instagram_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://instagram.com/someuser'));
    }

    public function test_ignores_tiktok_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://www.tiktok.com/@someuser'));
    }

    public function test_returns_null_when_no_link_present(): void
    {
        $this->assertNull(SocialMediaLink::findIn('just a normal message with no links'));
    }

    public function test_returns_first_match_when_multiple_links_present(): void
    {
        $text = 'first https://www.tiktok.com/@someuser/video/1234567890123456789 '
            . 'then https://twitter.com/someuser/status/1234567890';

        $link = SocialMediaLink::findIn($text);

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaLinkTest.php`
Expected: FAIL with "Class SocialMediaLink not found".

- [ ] **Step 4: Write the value object**

```php
<?php

namespace App\Services\SocialMedia;

final readonly class SocialMediaLink
{
    private const array PATTERNS = [
        'twitter' => '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/[^\/\s]+\/status\/\d+[^\s]*/i',
        'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel|tv)\/[A-Za-z0-9_-]+[^\s]*/i',
        'tiktok' => '/https?:\/\/(?:(?:www\.)?tiktok\.com\/@[^\/\s]+\/video\/\d+|v[mt]\.tiktok\.com\/[^\s]+)/i',
    ];

    public function __construct(
        public SocialMediaPlatform $platform,
        public string $url,
    ) {
    }

    public static function findIn(string $text): ?self
    {
        $earliestPlatform = null;
        $earliestUrl = null;
        $earliestOffset = null;

        foreach (self::PATTERNS as $platformValue => $pattern) {
            if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            [$url, $offset] = $matches[0];

            if ($earliestOffset !== null && $offset >= $earliestOffset) {
                continue;
            }

            $earliestPlatform = SocialMediaPlatform::from($platformValue);
            $earliestUrl = $url;
            $earliestOffset = $offset;
        }

        if ($earliestPlatform === null || $earliestUrl === null) {
            return null;
        }

        return new self($earliestPlatform, $earliestUrl);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaLinkTest.php`
Expected: PASS (12 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SocialMedia/SocialMediaPlatform.php app/Services/SocialMedia/SocialMediaLink.php tests/Unit/Services/SocialMedia/SocialMediaLinkTest.php
git commit -m "feat: add social media link detection"
```

---

### Task 2: Dockerfile — multi-stage `yt-dlp` + `ffmpeg` install

**Files:**
- Modify: `Dockerfile`

**Interfaces:**
- Produces: `yt-dlp` and `ffmpeg`/`ffprobe` binaries on `$PATH` inside the runtime image, consumed by `SocialMediaDownloadService` (Task 3) via bare command names.

**Context:** Verified via GitHub/Docker Hub that `yt-dlp` release `2026.07.04` publishes assets named exactly `yt-dlp_linux` and `SHA2-256SUMS` (format `<sha256>  <filename>`, two spaces), and `mwader/static-ffmpeg:8.1.2` exists on Docker Hub and ships binaries at `/ffmpeg` and `/ffprobe`. `sha256sum -c` requires the checksummed filename to exist under that exact name in the current directory, so the binary is downloaded as `yt-dlp_linux` in a scratch `WORKDIR` first, verified, then moved to its final name.

- [ ] **Step 1: Replace the full Dockerfile content**

Replace the entire contents of `Dockerfile` with:

```dockerfile
FROM ghcr.io/roadrunner-server/roadrunner:2.8.7 AS roadrunner
FROM mwader/static-ffmpeg:8.1.2 AS ffmpeg

FROM debian:bookworm-slim AS ytdlp-fetch
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
ARG YTDLP_VERSION=2026.07.04
WORKDIR /tmp/ytdlp
RUN curl -fL -o yt-dlp_linux \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && curl -fL -o SHA2-256SUMS \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/SHA2-256SUMS" \
    && grep "yt-dlp_linux$" SHA2-256SUMS | sha256sum -c - \
    && chmod +x yt-dlp_linux \
    && mv yt-dlp_linux /usr/local/bin/yt-dlp

FROM php:8.4.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
  apt-transport-https build-essential nano libzip-dev libonig-dev unzip \
  libjpeg62-turbo-dev libpng-dev libwebp-dev
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
  && docker-php-ext-install zip mbstring pdo_mysql mysqli sockets pcntl gd
RUN pecl install --onlyreqdeps --force redis && rm -rf /tmp/pear && docker-php-ext-enable redis
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=ffmpeg /ffmpeg /ffprobe /usr/local/bin/
COPY --from=ytdlp-fetch /usr/local/bin/yt-dlp /usr/local/bin/yt-dlp
RUN chmod +x /usr/local/bin/ffmpeg /usr/local/bin/ffprobe /usr/local/bin/yt-dlp
WORKDIR /app
COPY composer.json /app
COPY composer.lock /app
RUN composer install --ignore-platform-reqs --no-scripts -n --no-dev --no-cache --no-ansi --no-autoloader --no-scripts --prefer-dist
COPY . /app
RUN composer dump-autoload -n --optimize
EXPOSE 8090
ENTRYPOINT [ "rr", "serve", "-c", "/app/.rr.yaml" ]
```

- [ ] **Step 2: Build the image**

Run: `make build`
Expected: all three stages (`ffmpeg`, `ytdlp-fetch`, final) build without error; the `sha256sum -c` step prints `yt-dlp_linux: OK`.

- [ ] **Step 3: Start the container and verify both binaries are on `$PATH`**

Run: `make start`
Run: `./run yt-dlp --version`
Expected: prints `2026.07.04` (no "command not found").
Run: `./run ffmpeg -version`
Expected: prints an ffmpeg version banner (no "command not found").

- [ ] **Step 4: Validate the exact download command against a real post**

Run (substitute a real, currently-public Twitter/X status URL with a single photo that you have on hand):

```bash
./run yt-dlp --print-json --no-warnings \
  -o "/tmp/social-check/%(id)s_%(playlist_index|0)s.%(ext)s" \
  --format "best[filesize<48M]/best" \
  "<a public Twitter/X status URL>"
```

Expected: one file appears under `/tmp/social-check/` inside the container, and stdout contains exactly one JSON line with `id`, `ext`, `title`, `description`, `thumbnail` keys. This confirms the exact command shape Task 3 codes against before it's relied upon.

- [ ] **Step 5: Commit**

```bash
git add Dockerfile
git commit -m "feat: install yt-dlp and ffmpeg in a multi-stage Docker build"
```

---

### Task 3: `SocialMediaDownloadService` — success path

**Files:**
- Create: `app/Services/SocialMedia/SocialMediaResourceType.php`
- Create: `app/Services/SocialMedia/SocialMediaResource.php`
- Create: `app/Services/SocialMedia/SocialMediaDownloadException.php`
- Create: `app/Services/SocialMedia/SocialMediaDownloadService.php`
- Test: `tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php`

**Interfaces:**
- Consumes: `SocialMediaLink` (Task 1) — public `platform: SocialMediaPlatform`, `url: string`.
- Produces:
  - `SocialMediaResourceType` enum (cases `Photo`, `Video`).
  - `SocialMediaResource` readonly DTO: `type: SocialMediaResourceType`, `path: string`, `thumbnailPath: string`, `title: ?string`, `description: ?string`.
  - `SocialMediaDownloadException extends Exception`.
  - `SocialMediaDownloadService::download(SocialMediaLink $link): array` returning `array<int, SocialMediaResource>`, throws `SocialMediaDownloadException` on failure.
  - `SocialMediaDownloadService::cleanup(array $resources): void` where `$resources` is `array<int, SocialMediaResource>` — deletes the scratch directory those resources live in.

- [ ] **Step 1: Write the resource type enum**

```php
<?php

namespace App\Services\SocialMedia;

enum SocialMediaResourceType: string
{
    case Photo = 'photo';
    case Video = 'video';
}
```

- [ ] **Step 2: Write the resource DTO**

```php
<?php

namespace App\Services\SocialMedia;

final readonly class SocialMediaResource
{
    public function __construct(
        public SocialMediaResourceType $type,
        public string $path,
        public string $thumbnailPath,
        public ?string $title,
        public ?string $description,
    ) {
    }
}
```

- [ ] **Step 3: Write the exception**

```php
<?php

namespace App\Services\SocialMedia;

use Exception;

final class SocialMediaDownloadException extends Exception
{
}
```

- [ ] **Step 4: Write the failing tests for the success path**

```php
<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaPlatform;
use App\Services\SocialMedia\SocialMediaResourceType;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SocialMediaDownloadServiceTest extends TestCase
{
    private SocialMediaDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SocialMediaDownloadService();
    }

    public function test_downloads_single_photo(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/1111_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '1111',
                'ext' => 'jpg',
                'title' => 'A nice photo',
                'description' => 'Just a photo',
                'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/1111');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Photo, $resources[0]->type);
        $this->assertSame('A nice photo', $resources[0]->title);
        $this->assertSame('Just a photo', $resources[0]->description);
        $this->assertSame($resources[0]->path, $resources[0]->thumbnailPath);
        $this->assertFileExists($resources[0]->path);
    }

    public function test_downloads_single_video_and_its_thumbnail(): void
    {
        $thumbnailSourcePath = sys_get_temp_dir() . '/social-media-thumb-fixture-' . uniqid() . '.jpg';
        File::put($thumbnailSourcePath, 'fake-thumb-bytes');

        Process::fake(function (PendingProcess $process) use ($thumbnailSourcePath) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/2222_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '2222',
                'ext' => 'mp4',
                'title' => 'A nice video',
                'description' => null,
                'thumbnail' => $thumbnailSourcePath,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::TikTok, 'https://www.tiktok.com/@user/video/2222');

        $resources = $this->service->download($link);

        File::delete($thumbnailSourcePath);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Video, $resources[0]->type);
        $this->assertFileExists($resources[0]->thumbnailPath);
        $this->assertNotSame($resources[0]->path, $resources[0]->thumbnailPath);
    }

    public function test_downloads_multi_item_carousel(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/3333_1.jpg", 'fake-jpeg-1');
            File::put("{$scratchDir}/3333_2.jpg", 'fake-jpeg-2');

            $entries = [
                ['id' => '3333', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => 'Carousel', 'description' => null, 'thumbnail' => null],
                ['id' => '3333', 'ext' => 'jpg', 'playlist_index' => 2, 'title' => 'Carousel', 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/ABC123/');

        $resources = $this->service->download($link);

        $this->assertCount(2, $resources);
    }

    private function scratchDirFromCommand(array $command): string
    {
        $outputTemplate = $command[array_search('-o', $command, true) + 1];

        return dirname($outputTemplate);
    }
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php`
Expected: FAIL with "Class SocialMediaDownloadService not found".

- [ ] **Step 6: Write the service**

```php
<?php

namespace App\Services\SocialMedia;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class SocialMediaDownloadService
{
    private const int PROCESS_TIMEOUT_SECONDS = 45;
    private const int MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
    private const int MAX_RESOURCES = 10;
    private const array VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'mkv'];

    /**
     * @return array<int, SocialMediaResource>
     * @throws SocialMediaDownloadException
     */
    public function download(SocialMediaLink $link): array
    {
        $scratchDir = storage_path('app/tmp/social/' . Str::uuid()->toString());

        File::makeDirectory($scratchDir, 0755, true);

        try {
            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
                'yt-dlp',
                '--print-json',
                '--no-warnings',
                '-o', "{$scratchDir}/%(id)s_%(playlist_index|0)s.%(ext)s",
                '--format', 'best[filesize<48M]/best',
                $link->url,
            ]);
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException("не вдалося завантажити медіа: {$exception->getMessage()}");
        }

        if ($result->failed()) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        $resources = $this->buildResources($scratchDir, $this->parseEntries($result->output()));

        if ($resources === []) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException('усі файли за посиланням виявились завеликими або відсутніми');
        }

        return array_slice($resources, 0, self::MAX_RESOURCES);
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    public function cleanup(array $resources): void
    {
        File::deleteDirectory(dirname($resources[0]->path));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseEntries(string $output): array
    {
        $entries = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, SocialMediaResource>
     */
    private function buildResources(string $scratchDir, array $entries): array
    {
        $resources = [];

        foreach ($entries as $entry) {
            $path = $scratchDir . '/' . $this->expectedFilename($entry);

            if (! File::exists($path)) {
                continue;
            }

            if (File::size($path) > self::MAX_UPLOAD_BYTES) {
                continue;
            }

            $type = $this->resourceTypeForExtension($entry['ext'] ?? '');

            $thumbnailPath = $type === SocialMediaResourceType::Video
                ? ($this->downloadThumbnail($scratchDir, $entry['thumbnail'] ?? null) ?? $path)
                : $path;

            $resources[] = new SocialMediaResource(
                type: $type,
                path: $path,
                thumbnailPath: $thumbnailPath,
                title: $entry['title'] ?? null,
                description: $entry['description'] ?? null,
            );
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function expectedFilename(array $entry): string
    {
        $id = $entry['id'] ?? '';
        $playlistIndex = $entry['playlist_index'] ?? 0;
        $ext = $entry['ext'] ?? '';

        return "{$id}_{$playlistIndex}.{$ext}";
    }

    private function resourceTypeForExtension(string $extension): SocialMediaResourceType
    {
        return in_array(strtolower($extension), self::VIDEO_EXTENSIONS, true)
            ? SocialMediaResourceType::Video
            : SocialMediaResourceType::Photo;
    }

    private function downloadThumbnail(string $scratchDir, ?string $thumbnailUrl): ?string
    {
        if ($thumbnailUrl === null) {
            return null;
        }

        $bytes = @file_get_contents($thumbnailUrl);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $path = $scratchDir . '/' . Str::uuid()->toString() . '_thumb.jpg';

        File::put($path, $bytes);

        return $path;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Services/SocialMedia/SocialMediaResourceType.php app/Services/SocialMedia/SocialMediaResource.php app/Services/SocialMedia/SocialMediaDownloadException.php app/Services/SocialMedia/SocialMediaDownloadService.php tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php
git commit -m "feat: add SocialMediaDownloadService success path"
```

---

### Task 4: `SocialMediaDownloadService` — failure and edge cases

**Files:**
- Modify: `tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php`

**Interfaces:**
- Consumes: `SocialMediaDownloadService` (Task 3) unchanged — this task only adds test coverage for behavior the Task 3 implementation already contains (nonzero exit, unexpected `Throwable` from the process layer, per-item size filtering, `cleanup()`).

- [ ] **Step 1: Add failing tests for failure and edge-case behavior**

Add these methods to `SocialMediaDownloadServiceTest` (same class as Task 3):

```php
    public function test_throws_on_nonzero_exit_code_and_cleans_up_scratch_dir(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $capturedScratchDir = $this->scratchDirFromCommand($process->command);

            return Process::result(output: '', errorOutput: 'error: private post', exitCode: 1);
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/4444');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertDirectoryDoesNotExist($capturedScratchDir);
        }
    }

    public function test_throws_when_process_run_fails_unexpectedly_and_cleans_up_scratch_dir(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $capturedScratchDir = $this->scratchDirFromCommand($process->command);

            throw new RuntimeException('process could not start');
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/5555');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertDirectoryDoesNotExist($capturedScratchDir);
        }
    }

    public function test_drops_oversized_item_but_keeps_others(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/6666_1.jpg", str_repeat('a', 51 * 1024 * 1024));
            File::put("{$scratchDir}/6666_2.jpg", 'small-file');

            $entries = [
                ['id' => '6666', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => null, 'description' => null, 'thumbnail' => null],
                ['id' => '6666', 'ext' => 'jpg', 'playlist_index' => 2, 'title' => null, 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/OVERSIZED/');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertStringEndsWith('6666_2.jpg', $resources[0]->path);
    }

    public function test_throws_when_all_items_are_oversized(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/7777_0.jpg", str_repeat('a', 51 * 1024 * 1024));

            return Process::result(json_encode([
                'id' => '7777', 'ext' => 'jpg', 'title' => null, 'description' => null, 'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/TOOBIG/');

        $this->expectException(SocialMediaDownloadException::class);

        $this->service->download($link);
    }

    public function test_cleanup_deletes_scratch_directory(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $scratchDir = $this->scratchDirFromCommand($process->command);
            $capturedScratchDir = $scratchDir;

            File::put("{$scratchDir}/8888_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '8888', 'ext' => 'jpg', 'title' => null, 'description' => null, 'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/8888');

        $resources = $this->service->download($link);

        $this->assertDirectoryExists($capturedScratchDir);

        $this->service->cleanup($resources);

        $this->assertDirectoryDoesNotExist($capturedScratchDir);
    }
```

Add `use RuntimeException;` to the test file's imports (alongside the existing `use` statements) since `test_throws_when_process_run_fails_unexpectedly` constructs one directly.

- [ ] **Step 2: Run tests to verify the new ones pass**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php`
Expected: PASS (8 tests total). No production code changes are needed — Task 3's implementation already handles all of these; this step is a regression-proofing pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Services/SocialMedia/SocialMediaDownloadServiceTest.php
git commit -m "test: cover SocialMediaDownloadService failure and size-filtering edge cases"
```

---

### Task 5: `SocialMediaContextBuilder`

**Files:**
- Create: `app/Services/SocialMedia/SocialMediaContextBuilder.php`
- Test: `tests/Unit/Services/SocialMedia/SocialMediaContextBuilderTest.php`

**Interfaces:**
- Consumes: `SocialMediaResource` (Task 3) — `title: ?string`, `description: ?string`. `SocialMediaDownloadException` (Task 3) — `getMessage(): string`.
- Produces: `SocialMediaContextBuilder::buildSuccessMessage(SocialMediaResource $resource, string $userText): string`; `SocialMediaContextBuilder::buildFailureMessage(SocialMediaDownloadException $exception): string`. Both are consumed by `GenericmessageCommand` (Task 7) as the `$message` argument to `OpenAIService::generateResponse()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaContextBuilder;
use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaResource;
use App\Services\SocialMedia\SocialMediaResourceType;
use PHPUnit\Framework\TestCase;

class SocialMediaContextBuilderTest extends TestCase
{
    private SocialMediaContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SocialMediaContextBuilder();
    }

    public function test_builds_success_message_with_title_and_description(): void
    {
        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Photo,
            path: '/tmp/a.jpg',
            thumbnailPath: '/tmp/a.jpg',
            title: 'Funny cat',
            description: 'A cat doing a backflip',
        );

        $message = $this->builder->buildSuccessMessage($resource, 'глянь на це');

        $this->assertSame("Пост: \"Funny cat. A cat doing a backflip\"\n\nглянь на це", $message);
    }

    public function test_builds_success_message_with_only_title(): void
    {
        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Video,
            path: '/tmp/a.mp4',
            thumbnailPath: '/tmp/a.jpg',
            title: 'Funny cat',
            description: null,
        );

        $message = $this->builder->buildSuccessMessage($resource, 'глянь на це');

        $this->assertSame("Пост: \"Funny cat\"\n\nглянь на це", $message);
    }

    public function test_falls_back_to_user_text_when_no_metadata(): void
    {
        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Photo,
            path: '/tmp/a.jpg',
            thumbnailPath: '/tmp/a.jpg',
            title: null,
            description: null,
        );

        $message = $this->builder->buildSuccessMessage($resource, 'глянь на це');

        $this->assertSame('глянь на це', $message);
    }

    public function test_builds_failure_message_with_exception_reason(): void
    {
        $exception = new SocialMediaDownloadException('приватний акаунт');

        $message = $this->builder->buildFailureMessage($exception);

        $this->assertSame(
            'Не вдалося завантажити медіа за посиланням, причина: приватний акаунт. Відповідай у своєму стилі.',
            $message
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaContextBuilderTest.php`
Expected: FAIL with "Class SocialMediaContextBuilder not found".

- [ ] **Step 3: Write the builder**

```php
<?php

namespace App\Services\SocialMedia;

class SocialMediaContextBuilder
{
    public function buildSuccessMessage(SocialMediaResource $resource, string $userText): string
    {
        $descriptionParts = array_filter([$resource->title, $resource->description]);

        if ($descriptionParts === []) {
            return $userText;
        }

        $context = implode('. ', $descriptionParts);

        return "Пост: \"{$context}\"\n\n{$userText}";
    }

    public function buildFailureMessage(SocialMediaDownloadException $exception): string
    {
        return "Не вдалося завантажити медіа за посиланням, причина: {$exception->getMessage()}. Відповідай у своєму стилі.";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./run php vendor/bin/phpunit tests/Unit/Services/SocialMedia/SocialMediaContextBuilderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SocialMedia/SocialMediaContextBuilder.php tests/Unit/Services/SocialMedia/SocialMediaContextBuilderTest.php
git commit -m "feat: add SocialMediaContextBuilder"
```

---

### Task 6: Refactor `GenericmessageCommand` image encoding for reuse

**Files:**
- Modify: `app/Telegram/Commands/GenericmessageCommand.php:201-232`
- Test: `tests/Unit/Telegram/Commands/GenericmessageCommandImageEncodingTest.php`

**Interfaces:**
- Produces: `GenericmessageCommand::encodeImageBytesToDataUri(string $bytes): ?string` (private) — extracted from the existing GD-based logic in `downloadAndEncodeFileId()`. `GenericmessageCommand::encodeLocalImageToDataUri(string $path): ?string` (private, new) — reads a local file and delegates to `encodeImageBytesToDataUri()`. Consumed by Task 7's `replyWithSocialMediaResources()` to turn a downloaded thumbnail file into the `$imageDataUri` argument for `OpenAIService::generateResponse()`.

**Context:** `Telegram::__construct(string $api_key, string $bot_username = '')` and `Command::__construct(Telegram $telegram, ?Update $update = null)` are both lightweight (no DB/network calls), so the command can be instantiated directly in a bare-PHPUnit test without booting the full Laravel app or touching the database. The private methods are invoked via Reflection.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Telegram\Commands;

use App\Telegram\Commands\GenericmessageCommand;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GenericmessageCommandImageEncodingTest extends TestCase
{
    private GenericmessageCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = new Telegram('test-api-key', 'test_bot');
        $this->command = new GenericmessageCommand($telegram);
    }

    public function test_encode_image_bytes_to_data_uri_returns_jpeg_data_uri(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeImageBytesToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, $this->makeJpegBytes());

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    public function test_encode_image_bytes_to_data_uri_returns_null_for_invalid_bytes(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeImageBytesToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, 'not an image');

        $this->assertNull($dataUri);
    }

    public function test_encode_local_image_to_data_uri_reads_file_and_encodes_it(): void
    {
        $path = sys_get_temp_dir() . '/social-media-test-' . uniqid() . '.jpg';
        file_put_contents($path, $this->makeJpegBytes());

        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeLocalImageToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, $path);

        unlink($path);

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    public function test_encode_local_image_to_data_uri_returns_null_for_missing_file(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeLocalImageToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, '/nonexistent/path/to/file.jpg');

        $this->assertNull($dataUri);
    }

    private function makeJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./run php vendor/bin/phpunit tests/Unit/Telegram/Commands/GenericmessageCommandImageEncodingTest.php`
Expected: FAIL with "Call to undefined method ... encodeImageBytesToDataUri" (invoked via Reflection, so PHPUnit reports it as an error, not a missing class).

- [ ] **Step 3: Refactor `downloadAndEncodeFileId` and add the two new methods**

Replace `app/Telegram/Commands/GenericmessageCommand.php:201-232` (the `downloadAndEncodeFileId` method) with:

```php
    private function downloadAndEncodeFileId(string $fileId): ?string
    {
        $fileResponse = Request::getFile(['file_id' => $fileId]);
        if (! $fileResponse->isOk()) {
            Log::warning('Failed to getFile', ['file_id' => $fileId]);
            return null;
        }

        $filePath = $fileResponse->getResult()->getFilePath();
        $token = $this->telegram->getApiKey();
        $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        $bytes = @file_get_contents($url);
        if ($bytes === false || $bytes === '') {
            Log::warning('Failed to download file', ['url' => $url]);
            return null;
        }

        return $this->encodeImageBytesToDataUri($bytes);
    }

    private function encodeImageBytesToDataUri(string $bytes): ?string
    {
        // Convert to JPEG via GD (handles WebP, JPEG, PNG)
        $image = @\imagecreatefromstring($bytes);
        if ($image === false) {
            Log::warning('Failed to decode image bytes');
            return null;
        }

        ob_start();
        \imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        \imagedestroy($image);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function encodeLocalImageToDataUri(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('Failed to read local image file', ['path' => $path]);
            return null;
        }

        return $this->encodeImageBytesToDataUri($bytes);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./run php vendor/bin/phpunit tests/Unit/Telegram/Commands/GenericmessageCommandImageEncodingTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `./run php artisan test`
Expected: all existing tests still pass (this refactor changes internal structure only, not behavior).

- [ ] **Step 6: Commit**

```bash
git add app/Telegram/Commands/GenericmessageCommand.php tests/Unit/Telegram/Commands/GenericmessageCommandImageEncodingTest.php
git commit -m "refactor: extract reusable image-to-data-uri encoding in GenericmessageCommand"
```

---

### Task 7: Wire link detection and media reply into `GenericmessageCommand`

**Files:**
- Modify: `app/Telegram/Commands/GenericmessageCommand.php`
- Modify: `CLAUDE.md`
- Test: `tests/Feature/Telegram/Commands/GenericmessageCommandSocialMediaTest.php`

**Interfaces:**
- Consumes: `SocialMediaLink::findIn()` (Task 1), `SocialMediaDownloadService::download()`/`cleanup()` (Task 3), `SocialMediaDownloadException` (Task 3), `SocialMediaContextBuilder::buildSuccessMessage()`/`buildFailureMessage()` (Task 5), `GenericmessageCommand::encodeLocalImageToDataUri()` (Task 6), `OpenAIService::generateResponse()` (existing), `BaseCommand::sendText()`/`sendTyping()`/`createContext()`/`storeContext()`/`setChat()` (existing).
- Produces: the fully wired feature — no further tasks consume this one.

**Context:** `Request::sendPhoto`/`sendVideo` accept a local filesystem path directly in their `photo`/`video` fields (`Request::$input_file_fields` auto-wraps it via `file_exists()` + `Stream(Request::encodeFile($path))` inside `setUpRequestParams()`), and `Request::sendMediaGroup()` does the same for `InputMediaPhoto`/`InputMediaVideo` objects constructed with a raw local path in their `media` field (via `mediaInputHelper()`) — no manual `Request::encodeFile()` call is needed in either case. `reply_parameters => ['message_id' => $message->getMessageId()]` is used for threading instead of the deprecated `reply_to_message_id`; both achieve the same threaded-reply effect, but `reply_parameters` is the current, non-deprecated Bot API field. `$message->getMessageId()`, `$message->getChat()`, `$message->getCaption()` are resolved via `Entity::__call()`'s magic getter (snake_case property lookup), the same mechanism already relied on elsewhere in this file (e.g. `$message->getReplyToMessage()`).

- [ ] **Step 1: Add the new imports**

At the top of `app/Telegram/Commands/GenericmessageCommand.php`, add these `use` statements alongside the existing ones:

```php
use App\Services\SocialMedia\SocialMediaContextBuilder;
use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaResource;
use App\Services\SocialMedia\SocialMediaResourceType;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\InputMedia\InputMediaVideo;
```

- [ ] **Step 2: Insert the link-detection branch into `handle()`**

In `app/Telegram/Commands/GenericmessageCommand.php`, immediately after the existing line `$userText = $this->extractUserText($message);` and before `$imageDataUri = $this->extractImageDataUri($message)`, insert:

```php
        $link = SocialMediaLink::findIn($message->getText() ?? $message->getCaption() ?? '');

        if ($link !== null) {
            return $this->handleSocialMediaLink($message, $link, $userText);
        }

```

- [ ] **Step 3: Add the new private methods**

Add these methods to `GenericmessageCommand`, after `handle()` and before `shouldReply()`:

```php
    private function handleSocialMediaLink(Message $message, SocialMediaLink $link, string $userText): ServerResponse
    {
        $this->sendTyping();

        $contextBuilder = new SocialMediaContextBuilder();

        /** @var SocialMediaDownloadService $downloader */
        $downloader = app()->make(SocialMediaDownloadService::class);

        try {
            $resources = $downloader->download($link);
        } catch (SocialMediaDownloadException $exception) {
            return $this->replyWithSocialMediaFailure($contextBuilder, $exception);
        }

        try {
            return $this->replyWithSocialMediaResources($message, $resources, $contextBuilder, $userText);
        } finally {
            $downloader->cleanup($resources);
        }
    }

    private function replyWithSocialMediaFailure(SocialMediaContextBuilder $contextBuilder, SocialMediaDownloadException $exception): ServerResponse
    {
        /** @var OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $context = $this->createContext();

        $response = $openai->generateResponse(
            $contextBuilder->buildFailureMessage($exception),
            $this->chat->getPrompt(),
            $context,
        );

        $this->sendText($response);

        $this->storeContext($context);

        return Request::emptyResponse();
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    private function replyWithSocialMediaResources(Message $message, array $resources, SocialMediaContextBuilder $contextBuilder, string $userText): ServerResponse
    {
        /** @var OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $context = $this->createContext();

        $imageDataUri = $this->encodeLocalImageToDataUri($resources[0]->thumbnailPath);

        $caption = $openai->generateResponse(
            $contextBuilder->buildSuccessMessage($resources[0], $userText),
            $this->chat->getPrompt(),
            $context,
            $imageDataUri,
        ) ?? '';

        $this->sendSocialMediaResources($message, $resources, $caption);

        $this->storeContext($context);

        return Request::emptyResponse();
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    private function sendSocialMediaResources(Message $message, array $resources, string $caption): void
    {
        $replyParameters = ['message_id' => $message->getMessageId()];

        if (count($resources) === 1) {
            $this->sendSingleSocialMediaResource($resources[0], $caption, $replyParameters);
            return;
        }

        $media = [];

        foreach ($resources as $index => $resource) {
            $inputMediaData = ['media' => $resource->path];

            if ($index === 0) {
                $inputMediaData['caption'] = $caption;
            }

            $media[] = $resource->type === SocialMediaResourceType::Video
                ? new InputMediaVideo($inputMediaData)
                : new InputMediaPhoto($inputMediaData);
        }

        Request::sendMediaGroup([
            'chat_id' => $this->chat->tg_id,
            'media' => $media,
            'reply_parameters' => $replyParameters,
        ]);
    }

    /**
     * @param array{message_id: int} $replyParameters
     */
    private function sendSingleSocialMediaResource(SocialMediaResource $resource, string $caption, array $replyParameters): void
    {
        if ($resource->type === SocialMediaResourceType::Video) {
            Request::sendVideo([
                'chat_id' => $this->chat->tg_id,
                'video' => $resource->path,
                'caption' => $caption,
                'reply_parameters' => $replyParameters,
            ]);
            return;
        }

        Request::sendPhoto([
            'chat_id' => $this->chat->tg_id,
            'photo' => $resource->path,
            'caption' => $caption,
            'reply_parameters' => $replyParameters,
        ]);
    }
```

- [ ] **Step 4: Update CLAUDE.md**

In `CLAUDE.md`, in the "Telegram commands" section, find the paragraph starting `**Special command: \`GenericmessageCommand\`** handles every non-command message...` and append this sentence to it:

```
If that message contains a Twitter/X, Instagram, or TikTok post link, it instead downloads the post's media via `yt-dlp` (`App\Services\SocialMedia\SocialMediaDownloadService`) and replies with the attachment(s) as a threaded reply, captioned by an AI response built from the post's title/description (`SocialMediaContextBuilder`).
```

- [ ] **Step 5: Write the failing wiring test**

**Context:** `Longman\TelegramBot\Request::send()` (used internally by `sendPhoto`/`sendVideo`/`sendMediaGroup`/`sendMessage`/`sendChatAction`) goes through `Request::execute()`, which POSTs via a static Guzzle client set by `Request::setClient(ClientInterface $client)` (called automatically inside `Telegram::__construct()` as `Request::initialize($this)`, which keeps whatever client was already set). This means a `GuzzleHttp\Handler\MockHandler` combined with `GuzzleHttp\Middleware::history()` intercepts every outgoing Telegram API call and records the exact multipart/form body sent — no real network call, no code changes needed to make the vendor library testable. `BaseCommand::init()` (which touches the database via `Chat::firstOrCreate()`) is bypassed entirely by calling `GenericmessageCommand::handle()` directly (skipping `execute()`) and injecting an in-memory, unsaved `App\Models\Chat` via the existing public `BaseCommand::setChat()` setter — `Chat::getPrompt()` only reads the `prompt` attribute, so no database row is required.

Create `tests/Feature/Telegram/Commands/GenericmessageCommandSocialMediaTest.php`:

```php
<?php

namespace Tests\Feature\Telegram\Commands;

use App\Models\Chat;
use App\Services\OpenAIService;
use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaResource;
use App\Services\SocialMedia\SocialMediaResourceType;
use App\Telegram\Commands\GenericmessageCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenericmessageCommandSocialMediaTest extends TestCase
{
    private array $requestHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $responses = array_fill(0, 10, new Response(200, [], json_encode(['ok' => true, 'result' => true])));
        $handlerStack = HandlerStack::create(new MockHandler($responses));

        $this->requestHistory = [];
        $handlerStack->push(Middleware::history($this->requestHistory));

        Request::setClient(new Client(['handler' => $handlerStack]));
    }

    protected function tearDown(): void
    {
        Request::setClient(new Client());
        Mockery::close();
        parent::tearDown();
    }

    public function test_replies_with_photo_and_caption_when_link_download_succeeds(): void
    {
        $photoPath = $this->makeJpegFixture();

        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Photo,
            path: $photoPath,
            thumbnailPath: $photoPath,
            title: 'Funny cat',
            description: null,
        );

        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldReceive('download')->once()->andReturn([$resource]);
        $downloader->shouldReceive('cleanup')->once()->with([$resource]);
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')->once()->andReturn('Дивись, який кіт!');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('глянь https://twitter.com/user/status/999999');

        $command->handle();

        unlink($photoPath);

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendPhoto', (string) $request->getUri());

        $body = (string) $request->getBody();
        $this->assertStringContainsString('name="caption"', $body);
        $this->assertStringContainsString('Дивись, який кіт!', $body);
        $this->assertStringContainsString('name="reply_parameters"', $body);
    }

    public function test_replies_with_text_only_when_link_download_fails(): void
    {
        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldReceive('download')
            ->once()
            ->andThrow(new SocialMediaDownloadException('приватний акаунт'));
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'приватний акаунт'))
            ->andReturn('Ой, не вийшло, соррі.');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('глянь https://twitter.com/user/status/888888');

        $command->handle();

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendMessage', (string) $request->getUri());
        $this->assertStringContainsString('Ой, не вийшло, соррі.', (string) $request->getBody());
    }

    public function test_falls_through_to_existing_ai_flow_when_no_link_present(): void
    {
        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')->once()->andReturn('Просто відповідь.');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('привіт, як справи?');

        $command->handle();

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendMessage', (string) $request->getUri());
        $this->assertStringContainsString('Просто відповідь.', (string) $request->getBody());
    }

    private function makeCommand(string $text): GenericmessageCommand
    {
        $telegram = new Telegram('111111:test-api-key-xxxxxxxxxxxxxxxxxxxxxxxxxxx', 'test_bot');

        $update = new Update([
            'update_id' => 1,
            'message' => [
                'message_id' => 42,
                'date' => 1700000000,
                'chat' => ['id' => 555, 'type' => 'group', 'title' => 'Test Chat'],
                'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Tester', 'username' => 'tester_user'],
                'text' => "@test_bot {$text}",
            ],
        ], 'test_bot');

        $command = new GenericmessageCommand($telegram, $update);

        $chat = new Chat(['tg_id' => 555, 'title' => 'Test Chat', 'type' => 'group']);
        $chat->id = 1;
        $command->setChat($chat);

        return $command;
    }

    private function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        if ($this->requestHistory === []) {
            throw new RuntimeException('No Telegram API request was captured.');
        }

        return end($this->requestHistory)['request'];
    }

    private function makeJpegFixture(): string
    {
        $path = sys_get_temp_dir() . '/social-media-command-test-' . uniqid() . '.jpg';
        $image = imagecreatetruecolor(2, 2);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }
}
```

Note: `sendTyping()` fires an extra `sendChatAction` call before the meaningful reply in every scenario (and `BaseCommand::sendText()` fires `sendTyping()` again internally, plus a real 1-second `sleep()`, on the failure and no-link paths — pre-existing behavior, unrelated to this feature). Asserting on `end($this->requestHistory)` (the last captured call) rather than a fixed call count keeps the test focused on the outcome that matters and avoids coupling it to how many `sendChatAction` calls happen to occur along the way.

- [ ] **Step 6: Run the test to verify it fails**

Run: `./run php vendor/bin/phpunit tests/Feature/Telegram/Commands/GenericmessageCommandSocialMediaTest.php`
Expected: FAIL — at this point `GenericmessageCommand::handle()` doesn't yet detect links (Steps 1–3 of this task must already be applied to the file for this test to be meaningful; if run before Steps 1–3, it fails with undefined-method/class errors instead of assertion failures, which still confirms the wiring doesn't exist yet).

- [ ] **Step 7: Run the full automated test suite**

Run: `./run php artisan test`
Expected: all tests pass, including every test added in Tasks 1–6 and the 3 new tests in this task (the failure and no-link tests each take about 1 extra second due to the pre-existing `sleep()` inside `BaseCommand::sendText()`).

- [ ] **Step 8: Manual end-to-end verification**

The automated test in this task proves the wiring (link detected → correct Telegram endpoint called → correct payload), using a mocked `SocialMediaDownloadService`. It does not exercise the real `yt-dlp` binary, real platform URLs, or the video/multi-item/all-platform combinations already covered at the unit level in Tasks 1, 3, 4, and 5. Confirm those end-to-end with real traffic:

1. `make start`.
2. Start ngrok (`ngrok http 80`), copy the HTTPS URL into `.env` as both `APP_URL` and `TELEGRAM_BOT_WEBHOOK`, then run `./run php artisan webhook:set`.
3. In a real Telegram chat where the bot is present, @mention the bot with a Twitter/X status URL containing a single photo. Expected: a threaded photo reply with an in-character caption.
4. @mention the bot with a Twitter/X status URL containing a single video. Expected: a threaded video reply.
5. @mention the bot with a Twitter/X status URL for a multi-photo tweet. Expected: a single message containing an album (media group) of photos, with the caption on the first item only.
6. @mention the bot with an Instagram post link (`/p/...`). Expected: photo reply.
7. @mention the bot with an Instagram reel link (`/reel/...`). Expected: video reply.
8. @mention the bot with a TikTok video link. Expected: video reply.
9. @mention the bot with a link to a private or deleted post. Expected: a text-only in-character reply describing the failure — no crash, no silent no-op.
10. @mention the bot with a message that has no link. Expected: existing text-only AI behavior, unchanged (regression check).
11. After each of the sends above, run `./run ls storage/app/tmp/social/` and confirm no leftover directories remain.
12. `./run php artisan webhook:unset` when finished testing.

- [ ] **Step 9: Commit**

```bash
git add app/Telegram/Commands/GenericmessageCommand.php CLAUDE.md tests/Feature/Telegram/Commands/GenericmessageCommandSocialMediaTest.php
git commit -m "feat: reply with downloaded media when a message links a social media post"
```
