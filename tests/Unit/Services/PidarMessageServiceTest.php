<?php

namespace Tests\Unit\Services;

use App\Services\OpenAIService;
use App\Services\PidarMessageService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PidarMessageServiceTest extends TestCase
{
    private OpenAIService $openAI;
    private PidarMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openAI = Mockery::mock(OpenAIService::class);
        $this->service = new PidarMessageService($this->openAI);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_all_four_keys_for_standard_game(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $result = $this->service->generate();

        $this->assertIsArray($result);
        $this->assertSame(['start', 'step_1', 'step_2', 'result'], array_keys($result));
    }

    public function test_returns_five_keys_with_automated_trigger(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'automated_trigger' => 'Ось і я!',
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $result = $this->service->generate(withAutomatedTrigger: true);

        $this->assertIsArray($result);
        $this->assertSame(['automated_trigger', 'start', 'step_1', 'step_2', 'result'], array_keys($result));
    }

    public function test_returns_null_when_api_returns_null(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(null);

        $this->assertNull($this->service->generate());
    }

    public function test_returns_null_when_response_is_not_valid_json(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn('not json at all');

        $this->assertNull($this->service->generate());
    }

    public function test_returns_null_when_result_lacks_username_placeholder(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня знайдено!',
            ]));

        $this->assertNull($this->service->generate());
    }

    public function test_returns_null_when_result_has_duplicate_username_placeholder(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => ':username і :username — обидва підари!',
            ]));

        $this->assertNull($this->service->generate());
    }

    public function test_returns_null_when_a_required_key_is_missing(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $this->assertNull($this->service->generate());
    }

    public function test_returns_null_when_automated_trigger_key_missing_when_required(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $this->assertNull($this->service->generate(withAutomatedTrigger: true));
    }

    public function test_returns_null_when_api_throws_exception(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andThrow(new RuntimeException('API timeout'));

        $this->assertNull($this->service->generate());
    }

    public function test_prompt_includes_automated_trigger_examples_when_requested(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->withArgs(function (string $message, string $prompt, array $context = []) {
                return str_contains($message, 'automated_trigger');
            })
            ->andReturn(json_encode([
                'automated_trigger' => 'Ось і я!',
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $this->service->generate(withAutomatedTrigger: true);
        $this->addToAssertionCount(1);
    }

    public function test_prompt_excludes_automated_trigger_examples_when_not_requested(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->withArgs(function (string $message, string $prompt, array $context = []) {
                return ! str_contains($message, 'automated_trigger');
            })
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $this->service->generate(withAutomatedTrigger: false);
        $this->addToAssertionCount(1);
    }

    public function test_generate_for_key_returns_string_when_api_succeeds(): void
    {
        $this->app->setLocale('ua');

        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn('Підарами запахло, всім ховатись!');

        $result = $this->service->generateForKey('telegram.pidar-start');

        $this->assertIsString($result);
        $this->assertSame('Підарами запахло, всім ховатись!', $result);
    }

    public function test_generate_for_key_trims_whitespace_from_response(): void
    {
        $this->app->setLocale('ua');

        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn("  Підарами запахло!  \n");

        $this->assertSame('Підарами запахло!', $this->service->generateForKey('telegram.pidar-start'));
    }

    public function test_generate_for_key_returns_null_when_api_returns_null(): void
    {
        $this->app->setLocale('ua');

        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn(null);

        $this->assertNull($this->service->generateForKey('telegram.pidar-start'));
    }

    public function test_generate_for_key_returns_null_when_api_returns_blank_string(): void
    {
        $this->app->setLocale('ua');

        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn('   ');

        $this->assertNull($this->service->generateForKey('telegram.pidar-start'));
    }

    public function test_generate_for_key_returns_null_when_lang_key_not_found(): void
    {
        $this->openAI->shouldNotReceive('generateResponse');

        $this->assertNull($this->service->generateForKey('telegram.nonexistent-key'));
    }

    public function test_generate_for_key_returns_null_when_api_throws_exception(): void
    {
        $this->app->setLocale('ua');

        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->andThrow(new RuntimeException('API timeout'));

        $this->assertNull($this->service->generateForKey('telegram.pidar-start'));
    }
}
