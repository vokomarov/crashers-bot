<?php

namespace App\Telegram\Commands;

use App\Services\OpenAIService;
use App\Services\SocialMedia\SocialMediaContextBuilder;
use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaResource;
use App\Services\SocialMedia\SocialMediaResourceType;
use App\Telegram\BaseCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\InputMedia\InputMediaVideo;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class GenericmessageCommand extends BaseCommand
{
    const int REQUEST_LENGTH_LIMIT = 10000;
    const int CONTEXT_CACHE_TTL_SECONDS = 60 * 60;
    const int CONTEXT_COUNT_LIMIT = 100;

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

        $userText = $this->extractUserText($message);

        $linkMessage = $message;
        $link = SocialMediaLink::findIn($message->getText() ?? $message->getCaption() ?? '');

        if ($link === null && $message->getReplyToMessage() !== null) {
            $linkMessage = $message->getReplyToMessage();
            $link = SocialMediaLink::findIn($linkMessage->getText() ?? $linkMessage->getCaption() ?? '');
        }

        if ($link !== null) {
            return $this->handleSocialMediaLink($linkMessage, $link, $userText);
        }

        $imageDataUri = $this->extractImageDataUri($message)
            ?? $this->extractImageDataUri($message->getReplyToMessage());

        if ($imageDataUri !== null && $userText === '') {
            $userText = 'Це мем-відповідь на твою попередню репліку. Врахуй зображену ситуацію або емоцію під час створення відповіді.';
        }

        $request = $this->buildRequest($message, $userText);

        if ($request === '' && $imageDataUri === null) {
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

        $response = $openai->generateResponse($request, $this->chat->getPrompt(), $context, $imageDataUri);

        $this->sendText($response);

        $this->storeContext($context);

        return Request::emptyResponse();
    }

    private function handleSocialMediaLink(Message $message, SocialMediaLink $link, string $userText): ServerResponse
    {
        $this->sendTyping();

        $contextBuilder = new SocialMediaContextBuilder();

        /** @var SocialMediaDownloadService $downloader */
        $downloader = app()->make(SocialMediaDownloadService::class);

        try {
            $resources = $downloader->download($link);
        } catch (SocialMediaDownloadException $exception) {
            Log::warning('GenericmessageCommand: social media download failed', [
                'chat_id' => $this->chat?->id,
                'platform' => $link->platform->value,
                'url' => $link->url,
                'reason' => $exception->getMessage(),
            ]);

            return $this->replyWithSocialMediaFailure($contextBuilder, $exception);
        }

        try {
            return $this->replyWithSocialMediaResources($message, $resources, $userText);
        } finally {
            $downloader->cleanup($resources);
        }
    }

    private function replyWithSocialMediaFailure(SocialMediaContextBuilder $contextBuilder, SocialMediaDownloadException $exception): ServerResponse
    {
        /** @var OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $context = $this->createContext();

        $response = $openai->generateResponse(
            $contextBuilder->buildFailureMessage($exception),
            $this->chat->getPrompt(),
            $context,
        );

        $this->sendText($response);

        $this->storeContext($context);

        return Request::emptyResponse();
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    private function replyWithSocialMediaResources(Message $message, array $resources, string $userText): ServerResponse
    {
        /** @var OpenAIService $openai */
        $openai = app()->make(OpenAIService::class);

        $context = $this->createContext();

        $imageDataUri = $this->encodeLocalImageToDataUri($resources[0]->thumbnailPath);

        $caption = $openai->generateResponse(
            $userText,
            $this->chat->getPrompt(),
            $context,
            $imageDataUri,
        ) ?? '';

        $this->sendSocialMediaResources($message, $resources, $caption);

        $this->storeContext($context);

        return Request::emptyResponse();
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    private function sendSocialMediaResources(Message $message, array $resources, string $caption): void
    {
        $replyParameters = ['message_id' => $message->getMessageId()];

        if (count($resources) === 1) {
            $this->sendSingleSocialMediaResource($resources[0], $caption, $replyParameters);
            return;
        }

        $media = [];

        foreach ($resources as $index => $resource) {
            $inputMediaData = ['media' => $resource->path];

            if ($index === 0) {
                $inputMediaData['caption'] = $caption;
            }

            $media[] = $resource->type === SocialMediaResourceType::Video
                ? new InputMediaVideo($inputMediaData)
                : new InputMediaPhoto($inputMediaData);
        }

        Request::sendMediaGroup([
            'chat_id' => $this->chat->tg_id,
            'media' => $media,
            'reply_parameters' => $replyParameters,
        ]);
    }

    /**
     * @param array{message_id: int} $replyParameters
     */
    private function sendSingleSocialMediaResource(SocialMediaResource $resource, string $caption, array $replyParameters): void
    {
        if ($resource->type === SocialMediaResourceType::Video) {
            Request::sendVideo([
                'chat_id' => $this->chat->tg_id,
                'video' => $resource->path,
                'caption' => $caption,
                'reply_parameters' => $replyParameters,
            ]);
            return;
        }

        Request::sendPhoto([
            'chat_id' => $this->chat->tg_id,
            'photo' => $resource->path,
            'caption' => $caption,
            'reply_parameters' => $replyParameters,
        ]);
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

    private function buildRequest(Message $message, string $userText): string
    {
        $replyTo = $message->getReplyToMessage();

        if ($replyTo === null) {
            return $userText;
        }

        $text = $replyTo->getText();
        if ($text !== null && $text !== '') {
            return "Контекст: \"{$text}\"\n\n{$userText}";
        }

        $caption = $replyTo->getCaption();
        if ($caption !== null && $caption !== '') {
            return "Цитата: \"{$caption}\"\n\n{$userText}";
        }

        return $userText;
    }

    private function getBotMentionTag(): string
    {
        return '@' . $this->telegram->getBotUsername();
    }

    private function isMessageMentionBot(Message $message): bool
    {
        $text = $message->getText() ?? $message->getCaption();

        return $text !== null && str_contains($text, $this->getBotMentionTag());
    }

    private function extractUserText(Message $message): string
    {
        return $this->parseRequest($message->getText() ?? $message->getCaption());
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
            $context = array_slice($context, -self::CONTEXT_COUNT_LIMIT);
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

    private function extractImageDataUri(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $sticker = $message->getSticker();
        if ($sticker !== null) {
            // Prefer thumbnail: always static, available for animated/video stickers too
            $fileId = $sticker->getThumbnail()?->getFileId() ?? $sticker->getFileId();
            return $this->downloadAndEncodeFileId($fileId);
        }

        $photos = $message->getPhoto();
        if (!empty($photos)) {
            // Last element is the highest resolution
            $fileId = end($photos)->getFileId();
            return $this->downloadAndEncodeFileId($fileId);
        }

        return null;
    }

    private function downloadAndEncodeFileId(string $fileId): ?string
    {
        $fileResponse = Request::getFile(['file_id' => $fileId]);
        if (! $fileResponse->isOk()) {
            Log::warning('Failed to getFile', ['file_id' => $fileId]);
            return null;
        }

        $filePath = $fileResponse->getResult()->getFilePath();
        $token = $this->telegram->getApiKey();
        $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        $bytes = @file_get_contents($url);
        if ($bytes === false || $bytes === '') {
            Log::warning('Failed to download file', ['url' => $url]);
            return null;
        }

        return $this->encodeImageBytesToDataUri($bytes);
    }

    private function encodeImageBytesToDataUri(string $bytes): ?string
    {
        // Convert to JPEG via GD (handles WebP, JPEG, PNG)
        $image = @\imagecreatefromstring($bytes);
        if ($image === false) {
            Log::warning('Failed to decode image bytes');
            return null;
        }

        ob_start();
        \imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        \imagedestroy($image);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function encodeLocalImageToDataUri(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('Failed to read local image file', ['path' => $path]);
            return null;
        }

        return $this->encodeImageBytesToDataUri($bytes);
    }

}
