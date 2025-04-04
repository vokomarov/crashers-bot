<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\ServerResponse;

class PromptCacheResetCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'promptcachereset';

    /**
     * @var string
     */
    protected $description = 'Clear context cache of the chat.';

    /**
     * @var string
     */
    protected $usage = '/promptcachereset';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @return ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function handle(): ServerResponse
    {
        \Cache::delete('llm:context:chat:' . $this->chat->id);

        return $this->sendSuccess();
    }
}
