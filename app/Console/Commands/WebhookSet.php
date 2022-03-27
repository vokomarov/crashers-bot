<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Longman\TelegramBot\Exception\TelegramException;

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
     * @param \App\Telegram\TelegramClient $telegram
     * @return int
     */
    public function handle(TelegramClient $telegram)
    {
        try {
            $result = $telegram->setWebhook();

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
