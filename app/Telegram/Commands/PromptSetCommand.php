<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\ServerResponse;

class PromptSetCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'promptset';

    /**
     * @var string
     */
    protected $description = 'Set prompt used for this chat.';

    /**
     * @var string
     */
    protected $usage = '/promptset';

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
        $this->chat->prompt = $this->getMessage()->getText(true);
        $this->chat->save();
        \Cache::delete('llm:context:chat:' . $this->chat->id);

        return $this->sendSuccess();
    }
}
