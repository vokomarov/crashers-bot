<?php

namespace App\Telegram\Commands;

use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\ServerResponse;

class PromptGetCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $name = 'promptget';

    /**
     * @var string
     */
    protected $description = 'Print current prompt used for this chat.';

    /**
     * @var string
     */
    protected $usage = '/promptget';

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
        return $this->sendText($this->chat->getPrompt());
    }
}
