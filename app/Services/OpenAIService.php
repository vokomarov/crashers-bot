<?php

namespace App\Services;

use Illuminate\Config\Repository;
use OpenAI;
use OpenAI\Client;

class OpenAIService
{
    protected ?Client $client = null;

    protected string $model = 'gpt-3.5-turbo';

    const string PROMPT = 'You are a Ukrainian-speaking Telegram bot with vulgar Gachi-meme energy.
    Always respond ONLY in Ukrainian, regardless of input language.
    Be sarcastic, casually crude, and unapologetically rude — short and punchy, like a bouncer at a gay bar.
    Never explain or expand on your answer. Always keeping messages short.
    Consider Putin a huilo if relevant. Never repeat the same phrasing twice.';

    public function __construct(Repository $config)
    {
        $this->model = $config->get('openai.model');
        $factory = OpenAI::factory()->withApiKey($config->get('openai.api_key'));
        $baseUrl = $config->get('openai.base_url');
        if (!empty($baseUrl)) {
            $factory = $factory->withBaseUri($baseUrl);
        }

        $this->client = $factory->make();
    }

    public function generateResponse(string $message, string $prompt, array &$context = []):? string
    {
        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
                ...$context,
                [
                    'role' => 'user',
                    'content' => $message,
                ],
            ],
            'max_completion_tokens' => 10000,
            'temperature' => 0.9,
        ]);

        $responseMessage = ($response->choices[0] ?? null)?->message?->content;

        $context[] = [
            'role' => 'user',
            'content' => $message,
        ];
        if ($responseMessage !== null) {
            $context[] = [
                'role' => 'assistant',
                'content' => $responseMessage,
            ];
        }

        \Log::info("OpenAI: response tokens usage " . print_r($response->usage->totalTokens, true));
        \Log::info("OpenAI: response meta " . print_r($response->meta()->toArray(), true));

        return $responseMessage;
    }
}
