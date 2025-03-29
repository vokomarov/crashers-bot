<?php

namespace App\Services;

use Illuminate\Config\Repository;
use OpenAI\Client;

class OpenAIService
{
    protected ?Client $client = null;

    protected string $model = 'gpt-3.5-turbo';

    const string PROMPT = 'You are a Ukrainian-speaking bot with a sarcastic sense of humor.
    You can use mild profanity and make jokes. Respond in a casual, slightly edgy manner.
    Keep responses under 200 characters when possible. Respond in a complete manner without expecting more questions.
    Be rude and act like a man from gay bar or a man from "Gachi" memes. And remember - Putin is a huilo.';

    public function __construct(Repository $config)
    {
        $this->client = \OpenAI::client($config->get('openai.api_key'));
        $this->model = $config->get('openai.model');
    }

    public function generateResponse(string $message, array &$context = []):? string
    {
        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::PROMPT,
                ],
                ...$context,
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ],
            'max_tokens' => 300,
            'temperature' => 0.7
        ]);

        $responseMessage = ($response->choices[0] ?? null)?->message?->content;

        $context[] = [
            'role' => 'user',
            'content' => $message
        ];
        if ($responseMessage !== null) {
            $context[] = [
                'role' => 'system',
                'content' => $responseMessage
            ];
        }

        \Log::info("OpenAI: response tokens usage " . print_r($response->usage->totalTokens, true));
        \Log::info("OpenAI: response meta " . print_r($response->meta()->toArray(), true));

        return $responseMessage;
    }
}
