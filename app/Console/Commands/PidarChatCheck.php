<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Telegram\Commands\AdhocPidarCommand;
use App\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PidarChatCheck extends Command
{
    const MIN_PLAYERS = 2;
    const RETENTION_DAYS = 3;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pidar:chats-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for chats who scary to play game';

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
            $chats = $this->getChats();

            if (! $chats->count()) {
                $this->info('No outdated chats with enough users found');
                return 0;
            }

            $this->info("Found {$chats->count()} chats with outdated pidar history log");

            foreach ($chats as $chat) {
                try {
                    $this->process($chat);
                } catch (\Throwable $exception) {
                    Log::error("Unable to process pidar chat {$chat->id} checking: " . $exception->getMessage());
                    $this->error("Unable to process pidar chat {$chat->id}: " . $exception->getMessage());
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Unable to process pidar chats checking: ' . $exception->getMessage());
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
        $chats = Chat::has('users', '>=', self::MIN_PLAYERS)
                     ->whereDoesntHave('pidarHistoryLogs', function (Builder $builder) {
                         $builder->where('date', '>', Carbon::today()->subDays(self::RETENTION_DAYS));
                     })
                     ->get();

        return $chats;
    }

    /**
     * @param \App\Models\Chat $chat
     * @return void
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function process(Chat $chat): void
    {
        $this->info("Processing outdated pidar chat [{$chat->id}] {$chat->title}");

        $command = new AdhocPidarCommand($this->telegram->getClient());
        $command->call($chat);
    }
}
