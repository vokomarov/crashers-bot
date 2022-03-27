<?php

namespace App\Telegram;

use App\Telegram\Exceptions\InvalidWebhookTokenException;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Telegram;

class TelegramClient
{
    /**
     * @var \Longman\TelegramBot\Telegram|null
     */
    protected $client;

    /**
     * @var string
     */
    protected $token;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $webhook;

    /**
     * @var string
     */
    protected $webhookToken;

    /**
     * @var string
     */
    protected $commandsPath;

    /**
     * @var array
     */
    protected $allowedUpdated = [
        Update::TYPE_MESSAGE,
    ];

    /**
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->token = (string) $config->get('telegram.bot.token');
        $this->username = (string) $config->get('telegram.bot.username');
        $this->webhook = (string) $config->get('telegram.bot.webhook');
        $this->webhookToken = (string) $config->get('telegram.bot.webhook_token');
        $this->commandsPath = (string) $config->get('telegram.bot.commands_path');
    }

    /**
     * @param string $token
     * @return void
     * @throws \App\Telegram\Exceptions\InvalidWebhookTokenException
     */
    public function validateWebhookToken(string $token)
    {
        if ($this->webhookToken === $token) {
            return;
        }

        throw new InvalidWebhookTokenException('Received webhook token is invalid');
    }

    /**
     * @return \Longman\TelegramBot\Telegram
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function getClient(): Telegram
    {
        if ($this->client instanceof Telegram) {
            return $this->client;
        }

        return $this->client = new Telegram($this->token, $this->username);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return void
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function handle(Request $request)
    {
        $this->getClient()->setCustomInput($request->getContent());

        $this->getClient()->setCommandsPath($this->commandsPath);

        $this->getClient()->enableLimiter(['enabled' => true]);

        $this->getClient()->handle();
    }

    /**
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function setWebhook(): ServerResponse
    {
        $url = $this->getWebhookUrl();

        Log::info("Webhook URL: {$url}");

        return $this->getClient()->setWebhook($url, [
            'allowed_updates' => $this->allowedUpdated,
        ]);
    }

    /**
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function deleteWebhook(): ServerResponse
    {
        return $this->getClient()->deleteWebhook();
    }

    /**
     * @return string
     */
    protected function getWebhookUrl(): string
    {
        return $this->webhook . route('webhook', ['token' => $this->webhookToken], false);
    }
}
