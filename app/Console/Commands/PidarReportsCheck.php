<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Telegram\Commands\PidarMonthCommand;
use App\Telegram\Commands\PidarWeekCommand;
use App\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PidarReportsCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pidar:reports-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends regular reports to chats';

    /**
     * @var \App\Telegram\TelegramClient
     */
    protected TelegramClient $telegram;

    /**
     * Execute the console command.
     *
     * @param \App\Telegram\TelegramClient $telegram
     * @return int
     */
    public function handle(TelegramClient $telegram): int
    {
        $this->telegram = $telegram;

        try {
            foreach ($this->getChats() as $chat) {
                try {
                    $this->process($chat);
                } catch (\Throwable $exception) {
                    Log::error("Unable to process pidar chat {$chat->id} reports checking: " . $exception->getMessage());
                    $this->error("Unable to process pidar chat {$chat->id}: " . $exception->getMessage());
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Unable to process pidar chats reports checking: ' . $exception->getMessage());
            $this->error($exception->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<Chat>
     */
    protected function getChats(): Collection
    {
        return Chat::has('users')
                   ->has('pidarHistoryLogs')
                   ->get();
    }

    /**
     * @param \App\Models\Chat $chat
     * @return void
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function process(Chat $chat): void
    {
        $today = Carbon::today();

        if ($today->isSameDay(Carbon::today()->endOfMonth())) {
            $command = new PidarMonthCommand($this->telegram->getClient());
            $command->setChat($chat);
            $command->handle();
        } else if ($today->isSameDay(Carbon::today()->endOfWeek())) {
            $command = new PidarWeekCommand($this->telegram->getClient());
            $command->setChat($chat);
            $command->handle();
        }
    }
}
