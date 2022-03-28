# Crashers Bot

Crashers Telegram Bot was made to introduce more fun for Telegram chats.

## Features

- Open
- Free
- Secure (does not store conversations)

### Daily Pidar Game

Allows chat members to take part in the daily draw for the title of "Pidar of the day".

Participating only those chat members who will register themselves.

## Usage

Just add new member into your chat by searching for `CrashersBot`.

Available commands:

- `/pidarreg` - add you for the daily pidar game
- `/pidar` - play daily pidar game
- `/pidarall` - shows statistics over all time for a daily pidar game

## Local Development

### Setup

- Install docker
- Install [ngrok](https://ngrok.com/download).

```shell
$ cp .env.example .env
$ make build
$ make start
$ ./run composer install
$ ./run php artisan key:generate
$ ./run php artisan migrate
$ make stop
```

Your local setup is ready for daily usage.

### Daily Usage

```shell
$ ./ngrok http 80
```

copy HTTPS url from ngrok into `.env` file variables: `APP_URL`, `TELEGRAM_BOT_WEBHOOK`

```shell
$ make start
$ ./run php artisan webhook:unset
$ ./run php artisan webhook:set
```

Your local setup is ready to receive webhooks from Telegram.

At the end of work:

```shell
$ ./run php artisan webhook:unset
$ make stop
```
