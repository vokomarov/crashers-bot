<?php

namespace App\Telegram\Commands;

use App\Services\OpenAIService;
use App\Telegram\BaseCommand;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class GenericmessageCommand extends BaseCommand
{
    const int REQUEST_LENGTH_LIMIT = 300;

    protected $name = Telegram::GENERIC_MESSAGE_COMMAND;

    protected $description = 'Default command for any message';

    public function handle(): ServerResponse
    {
        $message = $this->getMessage();

        if ($message === null) {
            return Request::emptyResponse();
        }

        if (! $this->shouldReply($message)) {
            return Request::emptyResponse();
        }

        $request = $this->parseRequest($message->getText());

        if (! $this->isRequestValid($request)) {
            throw new \RuntimeException('Request is not valid for OpenAPI');
        }

        $this->sendTyping();

        /** @var \App\Services\OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $response = $openai->generateResponse($request, $this->createContextFromReplyTo($message));

        $this->sendText($response);

        return Request::emptyResponse();
    }

    private function shouldReply(Message $message): bool
    {
        // check is it direct mention
        if ($this->isMessageMentionBot($message)) {
            return true;
        }

        // check is it reply to bot's message
        if ($message->getReplyToMessage() !== null && $this->isMessageByBot($message->getReplyToMessage())) {
            return true;
        }

        return false;
    }

    private function isRequestValid(string $request): bool
    {
        return strlen($request) <= self::REQUEST_LENGTH_LIMIT;
    }

    private function createContextFromReplyTo(Message $message): array
    {
        $replyTo = $message->getReplyToMessage();
        $text = $replyTo?->getText();

        if ($replyTo === null || $text === null) {
            return [];
        }

        return [
            [
                'role' => $this->isMessageByBot($replyTo) ? 'system' : 'user',
                'content' => $text,
            ],
        ];
    }

    private function getBotMentionTag(): string
    {
        return '@' . $this->telegram->getBotUsername();
    }

    private function isMessageMentionBot(Message $message): bool
    {
        $text = $message->getText();

        return $text !== null && str_contains($text, $this->getBotMentionTag());
    }

    private function isMessageByBot(Message $message): bool
    {
        return $message->getFrom()?->getUsername() === $this->telegram->getBotUsername();
    }

    private function parseRequest(string $text): string
    {
        return trim(str_replace($this->getBotMentionTag(), '', $text));
    }
}
