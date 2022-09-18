<?php

namespace App\Telegram\Commands;

use App\Models\Chat;
use App\Models\User;

class AdhocPidarCommand extends PidarCommand
{
    protected User|null $sender = null;

    /**
     * @var string
     */
    protected $name = 'adhoc-pidar';

    /**
     * @var string
     */
    protected $description = 'Play pidar game to choose pidar of the day automatically';

    /**
     * @var string
     */
    protected $usage = '/adhoc-pidar';

    /**
     * @return void
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function call(): void
    {
        if ($this->findTodayLucky() !== null) {
            return;
        }

        $candidates = $this->chat->users()->get();

        $lucky = $this->chooseTodayLucky($candidates);

        $this->sendText($this->lang('telegram.pidar-automated-trigger'));

        $this->sendText($this->lang('telegram.pidar-start'));

        $this->sendText($this->lang('telegram.pidar-step-1'));

        $this->sendText($this->lang('telegram.pidar-step-2'));

        $this->sendText($this->lang('telegram.pidar-result', [
            'username' => "@{$lucky->username}"
        ]));
    }
}
