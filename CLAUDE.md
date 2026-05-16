# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel 10 / PHP 8.4 Telegram bot ("Crashers Bot") that runs inside Docker on [RoadRunner](https://roadrunner.dev/) (not php-fpm). It handles a daily "pidar of the day" game for Telegram group chats and responds to @mentions via OpenAI.

## Development commands

All commands run inside the container via the `./run` wrapper:

```shell
make build                         # build Docker image
make start                         # start DB container + app container
make stop                          # stop both
./run php artisan migrate          # run migrations
./run php artisan test             # run full test suite
./run php vendor/bin/phpunit --filter TestName   # run a single test
./run php artisan webhook:set      # register ngrok URL with Telegram
./run php artisan webhook:unset    # deregister webhook
./run php artisan pidar:chats-check  # manually trigger scheduled pidar check
```

For local development with Telegram webhooks, start ngrok (`ngrok http 80`), copy the HTTPS URL into `.env` as both `APP_URL` and `TELEGRAM_BOT_WEBHOOK`, then run `webhook:set`.

## Architecture

### Request flow

`POST /webhook/{token}` → `WebhookController` → `TelegramClient::handle()` → `longman/telegram-bot` dispatches to the matching command class.

The webhook token is validated against `TELEGRAM_BOT_WEBHOOK_TOKEN` in `.env` before processing.

### Telegram commands

All commands live in `app/Telegram/Commands/` and extend `BaseCommand` (which extends `longman/telegram-bot`'s `UserCommand`).

`BaseCommand::execute()` orchestrates the lifecycle:
1. `init()` — resolves or creates the `Chat` and `User` Eloquent models from the incoming message.
2. `handle()` — abstract, implemented by each command.

Helper methods on `BaseCommand`: `sendText()`, `sendTyping()`, `lang()` (random-picks from multi-line translation arrays in `lang/en.json`).

**Special command: `GenericmessageCommand`** handles every non-command message. It only replies when the bot is @mentioned or when replying to a bot message. It calls `OpenAIService` with a per-chat system prompt (stored on `Chat.prompt`, falling back to the default in `OpenAIService::PROMPT`) and persists conversation context in the cache (key `llm:context:chat:{id}`, TTL 1h, max 30 messages).

### Console commands (scheduled tasks)

`app/Console/Commands/`:
- `PidarChatCheck` (`pidar:chats-check`) — finds chats with `is_scheduled_pidar=true` that haven't played in >1 day and auto-triggers `AdhocPidarCommand` for them.
- `PidarReportsCheck` — sends weekly/monthly/yearly pidar stats.
- `PidarGift` — delivers gift messages to chats.

Scheduled in `app/Console/Kernel.php`.

### Data models

- `Chat` — one per Telegram chat, holds `tg_id`, `is_scheduled_pidar`, and optional `prompt` override.
- `User` — one per Telegram user, identified by `tg_id`.
- `Chat ↔ User` — M:N via `chats_users` (who has registered for the pidar game in each chat).
- `PidarHistoryLog` — one row per day per chat recording who was chosen.
- `PidarGift` / `PidarGiftItem` — gift event configuration.

### Infrastructure

- RoadRunner serves HTTP on port 8090 (mapped to host port 80 locally). Config: `.rr.yaml`.
- MySQL 8 in a separate Docker container
- No queue worker — jobs are synchronous (`QUEUE_CONNECTION=sync`).

## PHP / Laravel conventions

Use the `php-guidelines-from-spatie` skill for all PHP work.
