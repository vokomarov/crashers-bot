<?php

namespace App\Telegram\Commands;

use App\Services\OpenAIService;
use App\Telegram\BaseCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class GenericmessageCommand extends BaseCommand
{
    const int REQUEST_LENGTH_LIMIT = 300;
    const int CONTEXT_CACHE_TTL_SECONDS = 60 * 60;
    const int CONTEXT_COUNT_LIMIT = 30;

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
        if ($request === '') {
            Log::warning("Empty generic request for chatId {$this->chat?->id}", [
                'chat_id' => $message->getChat()?->getId(),
                'message' => json_encode($message),
            ]);
            return Request::emptyResponse();
        }

        if (! $this->isRequestValid($request)) {
            throw new \RuntimeException('Request is not valid for OpenAPI');
        }

        $this->sendTyping();

        /** @var \App\Services\OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $context = $this->createContext();
        $context = $this->createContextFromReplyTo($message, $context);

        $response = $openai->generateResponse($request, $this->chat->getPrompt(), $context);

        $this->sendText($response);

        $this->storeContext($context);

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

    private function createContextFromReplyTo(Message $message, array $context): array
    {
        $replyTo = $message->getReplyToMessage();
        $text = $replyTo?->getText();

        if ($replyTo === null || $text === null) {
            return $context;
        }

        $context[] = [
            'role' => $this->isMessageByBot($replyTo) ? 'system' : 'user',
            'content' => $text,
        ];

        return $context;
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

    private function parseRequest(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return trim(str_replace($this->getBotMentionTag(), '', $text));
    }

    private function createContext(): array
    {
        $key = $this->getContextCacheKey();

        $context = Cache::get($key);

        if (!is_array($context)) {
            return [];
        }

        return $context;
    }

    private function storeContext(array $context): void
    {
        if (count($context) > self::CONTEXT_COUNT_LIMIT) {
            $context = array_slice($context, (count($context) - 1) - self::CONTEXT_COUNT_LIMIT, self::CONTEXT_COUNT_LIMIT);
        }

        Cache::put($this->getContextCacheKey(), $context, self::CONTEXT_CACHE_TTL_SECONDS);
    }

    private function getContextCacheKey(): string
    {
        if ($this->chat?->id === null) {
            throw new \RuntimeException('Cannot create context cache key without loaded chat info');
        }

        return 'llm:context:chat:' . $this->chat->id;
    }

}
