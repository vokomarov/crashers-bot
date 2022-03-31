<?php

namespace App\Telegram;

use App\Models\Chat;
use App\Models\User;
use App\Telegram\Exceptions\EmptyNicknameException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\Chat as TelegramChat;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\User as TelegramUser;
use Longman\TelegramBot\Request;

abstract class BaseCommand extends UserCommand
{
    protected Chat|null $chat;

    protected User|null $sender;

    /**
     * Incoming command entrypoint
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute(): ServerResponse
    {
        try {
            $this->init();

            return $this->handle();
        } catch (EmptyNicknameException $exception) {
            Log::error("Exception on /{$this->name} command handling. {$exception->getMessage()}", [
                'message' => $this->getMessage()->toJson(),
            ]);

            return $this->sendText($this->lang('telegram.error-nickname-empty'));
        } catch (\Exception $exception) {
            Log::error("Exception on /{$this->name} command handling. {$exception->getMessage()}", [
                'message' => $this->getMessage()->toJson(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->sendError();
        }
    }

    /**
     * Command logic handler
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    abstract protected function handle(): ServerResponse;

    /**
     * Prepare command resources
     *
     * @return void
     * @throws \App\Telegram\Exceptions\EmptyNicknameException
     * @throws \RuntimeException
     */
    protected function init(): void
    {
        $message = $this->getMessage();

        $this->chat = $this->resolveChat($message->getChat());
        $this->sender = $this->resolveUser($message->getFrom());
    }

    /**
     * @param \Longman\TelegramBot\Entities\Chat $telegramChat
     * @return \App\Models\Chat
     * @throws \RuntimeException
     */
    protected function resolveChat(TelegramChat $telegramChat): Chat
    {
        $chat = Chat::firstOrCreate([
            'tg_id' => $telegramChat->getId()
        ], [
            'title' => $telegramChat->getTitle(),
            'type' => $telegramChat->getType(),
        ]);

        if ($chat instanceof Chat) {
            return $chat;
        }

        throw new \RuntimeException("Unable to resolve chat [TG ID {$telegramChat->getId()}]");
    }

    /**
     * @param \Longman\TelegramBot\Entities\User $telegramUser
     * @return \App\Models\User
     * @throws \App\Telegram\Exceptions\EmptyNicknameException
     * @throws \RuntimeException
     */
    protected function resolveUser(TelegramUser $telegramUser): User
    {
        if (empty($telegramUser->getUsername())) {
            throw new EmptyNicknameException("Nickname required to resolve user [TG ID {$telegramUser->getId()}]");
        }

        $user = User::firstOrCreate([
            'tg_id' => $telegramUser->getId(),
        ], [
            'username' => $telegramUser->getUsername(),
            'first_name' => $telegramUser->getFirstName(),
            'last_name' => $telegramUser->getLastName(),
        ]);

        if ($user instanceof User) {
            return $user;
        }

        throw new \RuntimeException("Unable to resolve user [TG ID {$telegramUser->getId()}]");
    }

    /**
     * Sending chat message on unexpected error
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function sendError()
    {
        return $this->sendText($this->lang('telegram.error'));
    }

    /**
     * Sending chat "typing" action
     *
     * @return void
     */
    protected function sendTyping()
    {
        if ($this->chat === null) {
            return;
        }

        Request::sendChatAction([
            'chat_id' => $this->chat->tg_id,
            'action'  => ChatAction::TYPING,
        ]);
    }

    /**
     * Sending chat text message with typing and some delay before sending text message
     *
     * @param string $message
     * @param int $delaySeconds
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    protected function sendText(string $message, int $delaySeconds = 1)
    {
        $this->sendTyping();

        sleep($delaySeconds);

        return $this->replyToChat($message);
    }

    /**
     * Fetch translation for a given key and get random entry for multiple lines
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function lang(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        if (is_array($line)) {
            return Arr::random($line);
        }

        return $line;
    }
}
