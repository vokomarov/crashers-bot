<?php

namespace App\Console\Commands;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;

class WebhookSet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhook:set';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install webhook to receive updates from Telegram servers';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(Repository $config)
    {
        try {
            $telegram = new Telegram($config->get('telegram.bot.token'), $config->get('telegram.bot.username'));

            $domain = $config->get('telegram.bot.webhook');
            $token = $config->get('telegram.bot.webhook_token');

            $webhook = $domain . route('webhook', ['token' => $token], false);

            $result = $telegram->setWebhook($webhook);

            if ($result->isOk()) {
                $this->info($result->getDescription());
            } else {
                $this->info($result->printError(true));
            }
        } catch (TelegramException $exception) {
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }
}
