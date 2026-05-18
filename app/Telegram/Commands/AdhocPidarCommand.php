<?php

namespace App\Telegram\Commands;

use App\Models\Chat;
use App\Models\User;
use App\Services\PidarMessageService;

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

        $messages = app()->make(PidarMessageService::class)->generate(withAutomatedTrigger: true);

        $this->sendText($messages['automated_trigger'] ?? $this->lang('telegram.pidar-automated-trigger'));

        $this->sendText($messages['start'] ?? $this->lang('telegram.pidar-start'));

        $this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));

        $this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

        $result = isset($messages['result'])
            ? str_replace(':username', "@{$lucky->username}", $messages['result'])
            : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

        $this->sendText($result);
    }
}
