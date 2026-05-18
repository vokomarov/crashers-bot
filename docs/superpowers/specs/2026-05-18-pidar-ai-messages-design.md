# Pidar AI-Generated Messages — Design Spec

**Date:** 2026-05-18

## Problem

The pidar-of-the-day game sends 4–5 hardcoded messages per run, randomly selected from 10–15 fixed Ukrainian meme lines per step. Players see repeats quickly. The goal is to generate fresh messages on every run using OpenAI, using the existing lines as few-shot style examples, while preserving the same meme tone and falling back to the hardcoded pool on any failure.

## Scope

- `PidarCommand` (manual `/pidar` command): 4 messages — start, step_1, step_2, result
- `AdhocPidarCommand` (automated daily trigger): 5 messages — automated_trigger + the same 4
- No changes to other commands, the stats/rating commands, or `OpenAIService`

## Architecture

### New class: `PidarMessageService`

`app/Services/PidarMessageService.php`

Single public method:

```php
public function generate(bool $withAutomatedTrigger = false): ?array
```

Returns an array on success:

```php
[
    'automated_trigger' => '...',  // only present when $withAutomatedTrigger = true
    'start'   => '...',
    'step_1'  => '...',
    'step_2'  => '...',
    'result'  => '... :username ...',
]
```

Returns `null` on any failure: API error, JSON decode failure, missing keys, or `result` not containing `:username`.

Internally it calls the existing `OpenAIService::generateResponse()` with a throwaway context array (no persistent state). No changes to `OpenAIService`.

### Prompt

**System prompt** (not shared with the chat reply prompt):

```
You are generating messages for a Ukrainian Telegram bot's daily "pidar of the day" game.
Tone: vulgar, sarcastic, meme-heavy, Gachi references welcome. Ukrainian only.
Rules:
- Generate exactly one message per step
- Do NOT reuse the example messages verbatim
- The "result" message MUST contain the placeholder :username exactly once
- Return ONLY valid JSON, no markdown, no extra text
```

**User message:** lists each step with a one-line role description and all existing translation lines from `lang/ua/telegram.php` as examples. Ends with the expected JSON shape.

When `$withAutomatedTrigger = false`, the `automated_trigger` step is omitted from both the prompt and the validated output.

### Validation

After JSON decode, check:
- All required keys are present and are non-empty strings
- `result` contains `:username` exactly once

Return `null` if any check fails.

### Command integration

Both `PidarCommand::handle()` and `AdhocPidarCommand::call()` call `generate()` once, upfront, before sending any messages. Each `sendText()` call uses null-coalescing to fall back to `$this->lang(...)`:

```php
$messages = app(PidarMessageService::class)->generate();

$this->sendText($messages['start']  ?? $this->lang('telegram.pidar-start'));
$this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));
$this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

$result = $messages['result']
    ? str_replace(':username', "@{$lucky->username}", $messages['result'])
    : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

$this->sendText($result);
```

For `AdhocPidarCommand`, `generate(withAutomatedTrigger: true)` is called and the `automated_trigger` message is sent first, with `$this->lang('telegram.pidar-automated-trigger')` as fallback.

The LLM call completes before the first `sendTyping()` + `sleep()`, so latency is absorbed into the game's existing pacing.

## Fallback behaviour

Any failure (exception, API error, invalid JSON, failed validation) returns `null` from `generate()`. Commands fall back to the existing `lang()` random-pick behaviour, silently. Failures are logged at error level.

## Files changed

| File | Change |
|------|--------|
| `app/Services/PidarMessageService.php` | New |
| `app/Telegram/Commands/PidarCommand.php` | Inject and use `PidarMessageService` |
| `app/Telegram/Commands/AdhocPidarCommand.php` | Inject and use `PidarMessageService` |

`OpenAIService`, `BaseCommand`, translation files, and all other commands are untouched.
