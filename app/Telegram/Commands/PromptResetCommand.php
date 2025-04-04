<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\ServerResponse;

class PromptResetCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'promptreset';

    /**
     * @var string
     */
    protected $description = 'Reset current prompt used for this chat to the default one.';

    /**
     * @var string
     */
    protected $usage = '/promptreset';

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
        $this->chat->prompt = null;
        $this->chat->save();
        \Cache::delete('llm:context:chat:' . $this->chat->id);

        return $this->sendSuccess();
    }
}
