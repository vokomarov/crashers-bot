<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Telegram\BaseCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Longman\TelegramBot\Entities\ServerResponse;

class PidarAllCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'pidarall';

    /**
     * @var string
     */
    protected $description = 'Statistics of a pidar game by all time';

    /**
     * @var string
     */
    protected $usage = '/pidarall';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     */
    public function handle(): ServerResponse
    {
        $luckiest = $this->getLuckiest();

        if ($luckiest->count() === 0) {
            return $this->sendText('Нема підарів 😢');
        }

        return $this->sendText($this->renderMessage($luckiest));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    protected function getLuckiest(): Collection
    {
        return User::withCount(['pidarHistoryLogs' => function (Builder $query) {
            $query->where('chat_id', $this->chat->id);
        }])->whereHas('chats', function (Builder $query) {
            $query->where('chat_id', $this->chat->id);
        })->orderByDesc('pidar_history_logs_count')->get();
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<\App\Models\User> $luckiest
     * @return string
     */
    protected function renderMessage(Collection $luckiest): string
    {
        $message = "Топ підарів за весь час:\n";

        foreach ($luckiest as $i => $lucky) {
            $position = $i + 1;

            if ($position === 1 && $lucky->pidar_history_logs_count === 0) {
                return 'Нема підарів 😢';
            }

            // TODO. Move this shit into translation files
            $times = $lucky->pidar_history_logs_count === 1 ? 'раз' : (in_array($lucky->pidar_history_logs_count, [2, 3, 4]) ? 'рази' : 'разів');

            $message .= "{$position} місце - @{$lucky->username}: {$lucky->pidar_history_logs_count} {$times}\n";
        }

        return $message;
    }
}
