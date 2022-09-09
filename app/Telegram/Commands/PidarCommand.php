<?php

namespace App\Telegram\Commands;

use App\Models\PidarHistoryLog;
use App\Models\User;
use App\Telegram\BaseCommand;
use Illuminate\Database\Eloquent\Collection;
use Longman\TelegramBot\Entities\ServerResponse;

class PidarCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'pidar';

    /**
     * @var string
     */
    protected $description = 'Play pidar game to choose pidar of the day';

    /**
     * @var string
     */
    protected $usage = '/pidar';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     */
    public function handle(): ServerResponse
    {
        $lucky = $this->findTodayLucky();

        if ($lucky !== null) {
            return $this->sendText($this->lang('telegram.pidar-already-exists', [
                'username' => "@{$lucky->username}",
            ]));
        }

        $candidates = $this->chat->users()->get();

        if (! $candidates->find($this->sender->id) instanceof User) {
            $this->chat->users()->attach($this->sender);

            $this->sendText($this->lang('telegram.pidar-triggered-not-registered'));

            $candidates->push($this->sender);
        }

        $lucky = $this->chooseTodayLucky($candidates);

        $this->sendText($this->lang('telegram.pidar-start'));

        $this->sendText($this->lang('telegram.pidar-step-1'));

        $this->sendText($this->lang('telegram.pidar-step-2'));

        return $this->sendText($this->lang('telegram.pidar-result', [
            'username' => "@{$lucky->username}"
        ]));
    }

    /**
     * Find today's lucky user if exists
     *
     * @return \App\Models\User|null
     */
    protected function findTodayLucky():? User
    {
        $historyLog = PidarHistoryLog::with('pidarUser')
                                     ->where('chat_id', $this->chat->id)
                                     ->where('date', today()->toDateString())
                                     ->first();

        if (! $historyLog instanceof PidarHistoryLog || !$historyLog->pidarUser instanceof User) {
            return null;
        }

        return $historyLog->pidarUser;
    }

    /**
     * Choose today's lucky user and log it
     *
     * @param \Illuminate\Database\Eloquent\Collection $candidates
     * @return \App\Models\User
     */
    protected function chooseTodayLucky(Collection $candidates): User
    {
        if ($candidates->count() === 0) {
            throw new \RuntimeException('No candidates for lucky user');
        }

        $lucky = $candidates->random(1)->first();

        $this->logLucky($lucky);

        return $lucky;
    }

    /**
     * @param \App\Models\User $lucky
     * @return void
     */
    protected function logLucky(User $lucky): void
    {
        $log = new PidarHistoryLog();
        $log->chat_id = $this->chat->id;
        $log->sender_user_id = $this->sender->id;
        $log->pidar_user_id = $lucky->id;
        $log->date = today()->toDateString();
        $log->save();
    }
}
