<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Longman\TelegramBot\Exception\TelegramException;

class WebhookUnset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhook:unset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove Webhook for bot';

    /**
     * Execute the console command.
     *
     * @param \App\Telegram\TelegramClient $telegram
     * @return int
     */
    public function handle(TelegramClient $telegram)
    {
        try {
            $result = $telegram->deleteWebhook();

            $this->info($result->getDescription());
        } catch (TelegramException $exception) {
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }
}
