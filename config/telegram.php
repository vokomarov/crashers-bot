<?php

return [
    'bot' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook' => env('TELEGRAM_BOT_WEBHOOK'),
        'webhook_token' => env('TELEGRAM_BOT_WEBHOOK_TOKEN'),
        'commands_path' => app_path('Telegram/Commands'),
    ],
];
