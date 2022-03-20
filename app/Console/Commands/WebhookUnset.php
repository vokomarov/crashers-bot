<?php

namespace App\Console\Commands;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

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
     * @return int
     */
    public function handle(Repository $config)
    {
        try {
            $telegram = new Telegram($config->get('telegram.bot.token'), $config->get('telegram.bot.username'));

            $result = $telegram->deleteWebhook();

            $this->info($result->getDescription());
        } catch (TelegramException $exception) {
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }
}
