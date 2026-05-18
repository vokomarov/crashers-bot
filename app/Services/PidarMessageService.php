<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class PidarMessageService
{
    private const array STEPS = ['start', 'step_1', 'step_2', 'result'];
    private const array STEPS_WITH_TRIGGER = ['automated_trigger', 'start', 'step_1', 'step_2', 'result'];

    private const array STEP_LANG_KEYS = [
        'automated_trigger' => 'telegram.pidar-automated-trigger',
        'start'             => 'telegram.pidar-start',
        'step_1'            => 'telegram.pidar-step-1',
        'step_2'            => 'telegram.pidar-step-2',
        'result'            => 'telegram.pidar-result',
    ];

    private const array STEP_DESCRIPTIONS = [
        'automated_trigger' => 'bot announces it is starting the game automatically, unprompted',
        'start'             => 'opening announcement that the pidar search has begun',
        'step_1'            => 'first suspense-building intermediate message',
        'step_2'            => 'second suspense message, almost revealing the winner',
        'result'            => 'announcing the winner — MUST include :username placeholder exactly once',
    ];

    public function __construct(private readonly OpenAIService $openAI) {}

    public function generate(bool $withAutomatedTrigger = false): ?array
    {
        try {
            $response = $this->openAI->generateResponse(
                $this->buildUserMessage($withAutomatedTrigger),
                $this->buildSystemPrompt(),
            );

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);

            if (! is_array($data)) {
                Log::error('PidarMessageService: invalid JSON response', ['response' => $response]);
                return null;
            }

            if (! $this->validate($data, $withAutomatedTrigger)) {
                Log::error('PidarMessageService: validation failed', ['data' => $data]);
                return null;
            }

            $steps = $withAutomatedTrigger ? self::STEPS_WITH_TRIGGER : self::STEPS;
            return array_intersect_key($data, array_flip($steps));

        } catch (Throwable $exception) {
            Log::error('PidarMessageService: exception', ['error' => $exception->getMessage()]);
            return null;
        }
    }

    public function generateForKey(string $langKey): ?string
    {
        $translation = __($langKey);

        if (! is_array($translation) || empty($translation)) {
            return null;
        }

        try {
            $examplesText = implode("\n", array_map(fn($example) => "\"$example\"", $translation));
            $userMessage = "Generate one fresh message in the same style and tone as these examples. Do NOT copy them verbatim. Respond with the message text only — no quotes, no extra explanation.\n\nExamples:\n{$examplesText}";

            $response = $this->openAI->generateResponse(
                $userMessage,
                $this->buildSystemPrompt(),
            );

            if ($response === null || trim($response) === '') {
                return null;
            }

            return trim($response);

        } catch (Throwable $exception) {
            Log::error('PidarMessageService: exception in generateForKey', ['key' => $langKey, 'error' => $exception->getMessage()]);
            return null;
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are generating messages for a Ukrainian Telegram bot's daily "pidar of the day" game.
Tone: vulgar, sarcastic, meme-heavy, Gachi references welcome. Ukrainian only.
Rules:
- Generate exactly one message per step
- Do NOT reuse the example messages verbatim
- The "result" message MUST contain the placeholder :username exactly once
- Return ONLY valid JSON, no markdown, no extra text
PROMPT;
    }

    private function buildUserMessage(bool $withAutomatedTrigger): string
    {
        $steps = $withAutomatedTrigger ? self::STEPS_WITH_TRIGGER : self::STEPS;

        $parts = ["Generate one fresh message per step. Style and tone must match the examples. Do NOT copy examples verbatim.\n"];

        foreach ($steps as $step) {
            $examples = (array) __(self::STEP_LANG_KEYS[$step]);
            $examplesText = implode("\n", array_map(fn($example) => "\"$example\"", $examples));
            $parts[] = "STEP: {$step}\nRole: " . self::STEP_DESCRIPTIONS[$step] . "\nExamples:\n{$examplesText}";
        }

        $jsonShape = '{' . implode(', ', array_map(fn($step) => "\"$step\": \"...\"", $steps)) . '}';
        $parts[] = "Respond with JSON only:\n{$jsonShape}";

        return implode("\n\n", $parts);
    }

    private function validate(array $data, bool $withAutomatedTrigger): bool
    {
        $required = $withAutomatedTrigger ? self::STEPS_WITH_TRIGGER : self::STEPS;

        foreach ($required as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                return false;
            }
        }

        return str_contains($data['result'], ':username');
    }
}
