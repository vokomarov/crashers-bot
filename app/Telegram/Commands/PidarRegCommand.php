<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\ServerResponse;

class PidarRegCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'pidarreg';

    /**
     * @var string
     */
    protected $description = 'Register for a pidar game';

    /**
     * @var string
     */
    protected $usage = '/pidarreg';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     */
    public function handle(): ServerResponse
    {
        if ($this->isRegistered()) {
            return $this->sendText('Вже записаний, готуй очко');
        }

        $this->register();

        return $this->sendText('Записав, маладєц, маєш яйця');
    }

    /**
     * @return bool
     */
    protected function isRegistered(): bool
    {
        return $this->chat->users()->wherePivot('user_id', $this->sender->id)->exists();
    }

    /**
     * @return void
     */
    protected function register(): void
    {
        $this->chat->users()->attach($this->sender);
    }
}
