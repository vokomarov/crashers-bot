# Pidar AI-Generated Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace hardcoded random pidar game messages with OpenAI-generated ones on every game run, falling back to the existing translation pool on failure.

**Architecture:** A new `PidarMessageService` makes a single OpenAI call per game run, passing all existing translation lines as few-shot examples and requesting all game step messages back as JSON. Both `PidarCommand` and `AdhocPidarCommand` call the service upfront and use null-coalescing to fall back to `lang()` per step if generation fails.

**Tech Stack:** PHP 8.4, Laravel 10, `openai-php/client` (via existing `OpenAIService`), Mockery for tests, PHPUnit 10.

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `app/Services/PidarMessageService.php` | Build prompt with few-shot examples, call OpenAI, parse/validate JSON |
| Create | `tests/Unit/Services/PidarMessageServiceTest.php` | Unit tests for PidarMessageService |
| Modify | `app/Telegram/Commands/PidarCommand.php` | Call PidarMessageService before sending messages, fallback per step |
| Modify | `app/Telegram/Commands/AdhocPidarCommand.php` | Same, with `withAutomatedTrigger: true` |

---

## Task 1: Create `PidarMessageService`

**Files:**
- Create: `app/Services/PidarMessageService.php`
- Create: `tests/Unit/Services/PidarMessageServiceTest.php`

---

- [ ] **Step 1.1: Write the failing tests**

Create `tests/Unit/Services/PidarMessageServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\OpenAIService;
use App\Services\PidarMessageService;
use Mockery;
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
            ->andThrow(new \RuntimeException('API timeout'));

        $this->assertNull($this->service->generate());
    }

    public function test_prompt_includes_automated_trigger_examples_when_requested(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->withArgs(function (string $message, string $prompt) {
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
    }

    public function test_prompt_excludes_automated_trigger_examples_when_not_requested(): void
    {
        $this->openAI
            ->shouldReceive('generateResponse')
            ->once()
            ->withArgs(function (string $message, string $prompt) {
                return !str_contains($message, 'automated_trigger');
            })
            ->andReturn(json_encode([
                'start'  => 'Починаємо!',
                'step_1' => 'Шукаємо...',
                'step_2' => 'Знайшли!',
                'result' => 'Підар дня :username!',
            ]));

        $this->service->generate(withAutomatedTrigger: false);
    }
}
```

- [ ] **Step 1.2: Run tests to verify they fail**

```bash
./run php vendor/bin/phpunit --filter PidarMessageServiceTest
```

Expected: errors like `Class "App\Services\PidarMessageService" not found`

---

- [ ] **Step 1.3: Create `PidarMessageService`**

Create `app/Services/PidarMessageService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PidarMessageService
{
    private const array STEPS = ['start', 'step_1', 'step_2', 'result'];
    private const array STEPS_WITH_TRIGGER = ['automated_trigger', 'start', 'step_1', 'step_2', 'result'];

    private const array EXAMPLES = [
        'automated_trigger' => [
            'Давно підарів не бачив..',
            'Скучили? 😳',
            'А я й забув про вас (ні, не забув)..',
            'Не чекали? А я вже тут 🌚',
            '<b>ДВЕРІ ВІДКРИВАЙТЕ!</b>',
            'Ранок починається не з кави, а з нових підарів',
            'Сьогодні особливий день. Чому? Тому що.',
            'Якісь перешкоди, викликані ретроградним Юпітером, немає часу пояснювати.',
            'Ох-ох, тільки не кажіть, що забули за мене. А я за вас ні 😄',
            'Спеціальна пропозиція, тільки сьогодні і тільки для вас. Один підар за ціною двох.',
            'Руки на стіл! 👊',
        ],
        'start' => [
            'Всім ховатись, але це вам не допоможе',
            'Підарами запахло',
            '‼️ Підарна тривога!',
            '♂️Служба пошуку підарів♂️ активована!',
            '🛑УВАГА! ПІДАРНА ТРИВОГА🛑',
            'AVENGERS, ASSemble!',
            'Добрий вечір, діти! Знов позаходили під чужими іменами..',
            'Ділом займись, єблан..',
            'Інформаційно-аналітична система розпізнавання квазіпікових сигналів підарів активована',
            'Доброго вечора, хто ♂️boss of this gym♂️?',
            'Зараз я перевірю кожну ♂️ass hole♂️ в цьому чаті!',
            'Так, так, так',
            'Можливо, той хто мене розбудив, і є підар?',
            'Всі пісюни на рояль, щоб я бачив. Починаю пошук...',
        ],
        'step_1' => [
            'Охрана отмєна',
            'СБУ вже виїхало',
            'Я їбав такі качелі…',
            'Всім негайно в укриття!',
            'Жоден ♂️fat cock♂️ не сховається!',
            'Божечки, божечки..',
            'Ви в зоні ризику, рекомендую не вийобуваться.',
            'А где бомбілі 8 ти бил назад лет донбас когда?',
            'Еслі ви відєлі підара, обратітєсь по номеру 937-99-92..',
            'Підключення до GPS (Global Pidar Search)..',
            'Пуск ракети РЕП(Радіо-електронної підарасні). 300-30-3!',
        ],
        'step_2' => [
            'Наводжу справки з воєнкомата..',
            'Розрахунок цілі',
            'Координати очка знайдені!',
            'Мама, я в плєну, мой БТР падбітий..',
            'Ох ох, мій детектор підарів зашкалює',
            'Не сцять, пошук вже завершено',
            'А я знаю, що ти дрочиш',
            'Вилкою в глаз, чи в жопу раз? Як бачиш, вилки у мене нема',
            'Госпаді, як малі діти..',
            'ЯРІК БЛЯТЬ, БАЧОК!!!',
            'О блять, знайомі люди, нічого нового..',
            'Перевіряю усі вхідні отвори...',
            'Готуюсь винести вердикт, доки очко підара не остигло',
            'Скільки кажеш хлопців у тебе було?',
            'Чую протяг з чийогось очка, іду на звук',
        ],
        'result' => [
            'Підар дня :username 👏',
            'Привітайте підара дня :username',
            'За обширні досягнення у ході експлуатації очка, підаром дня нагороджується :username <i>Підр, підр, УРА!</i>',
            'Місто має знати своїх героїв. Сьогодні :username - Палій Трави!',
            'Не розчаровуйся, :username, це краще, ніж бути русскім.',
            'А сьогодні, в цей чудовий час доби, ♂підаром♂ дня стає :username! Хоч і все ще не ♂dungeon master♂',
            'Вас багато, а от підар дня один - :username 🎉',
            'А сегодня в завтрашний день не все могут смотреть на підара дня :username! 🎉🎉🎉',
            'Всеукраїнським СМС голосуванням, підаром дня обрано :username ! <b>Вітаємо переможця!</b>',
            ':username, ну ти і ♂геймер♂..',
            'Урочисто привітаймо :username - підара дня. Хай лунають тости, Тости, ТОСТИ!! Хай танцюють гості, Гості ГОСТІ!!',
            'Останні знімки телескопа Hubble довели, що :username сьогодні підар дня!',
            'Я ЖИ-ВИИИЙ, Я СИЛЬ-НИЙИЙ, але трохи підар - Я :username',
            'Ну нічого, що :username - підар, головне, щоб людиною був хорошою (ніт)',
            'Не знаю як Ляшко, а от :username - підар',
        ],
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
            $context = [];
            $response = $this->openAI->generateResponse(
                $this->buildUserMessage($withAutomatedTrigger),
                $this->buildSystemPrompt(),
                $context,
            );

            if ($response === null) {
                return null;
            }

            $data = json_decode($response, true);

            if (!is_array($data)) {
                Log::error('PidarMessageService: invalid JSON response', ['response' => $response]);
                return null;
            }

            if (!$this->validate($data, $withAutomatedTrigger)) {
                Log::error('PidarMessageService: validation failed', ['data' => $data]);
                return null;
            }

            $steps = $withAutomatedTrigger ? self::STEPS_WITH_TRIGGER : self::STEPS;
            return array_intersect_key($data, array_flip($steps));

        } catch (\Throwable $e) {
            Log::error('PidarMessageService: exception', ['error' => $e->getMessage()]);
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
            $examples = implode("\n", array_map(fn($e) => "\"$e\"", self::EXAMPLES[$step]));
            $parts[] = "STEP: {$step}\nRole: " . self::STEP_DESCRIPTIONS[$step] . "\nExamples:\n{$examples}";
        }

        $jsonShape = '{' . implode(', ', array_map(fn($s) => "\"$s\": \"...\"", $steps)) . '}';
        $parts[] = "Respond with JSON only:\n{$jsonShape}";

        return implode("\n\n", $parts);
    }

    private function validate(array $data, bool $withAutomatedTrigger): bool
    {
        $required = $withAutomatedTrigger ? self::STEPS_WITH_TRIGGER : self::STEPS;

        foreach ($required as $key) {
            if (empty($data[$key]) || !is_string($data[$key])) {
                return false;
            }
        }

        return str_contains($data['result'], ':username');
    }
}
```

- [ ] **Step 1.4: Run tests to verify they pass**

```bash
./run php vendor/bin/phpunit --filter PidarMessageServiceTest
```

Expected: all 10 tests PASS

- [ ] **Step 1.5: Commit**

```bash
git add app/Services/PidarMessageService.php tests/Unit/Services/PidarMessageServiceTest.php
git commit -m "feat: add PidarMessageService for AI-generated game messages"
```

---

## Task 2: Integrate `PidarMessageService` into `PidarCommand`

**Files:**
- Modify: `app/Telegram/Commands/PidarCommand.php`

---

- [ ] **Step 2.1: Replace the message-sending block in `handle()`**

Open `app/Telegram/Commands/PidarCommand.php`. Replace the four `sendText` calls at the end of `handle()` (lines 58–66) with the following:

```php
$messages = app(\App\Services\PidarMessageService::class)->generate();

$this->sendText($messages['start'] ?? $this->lang('telegram.pidar-start'));

$this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));

$this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

$result = isset($messages['result'])
    ? str_replace(':username', "@{$lucky->username}", $messages['result'])
    : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

return $this->sendText($result);
```

The full `handle()` method should look like this after the change:

```php
public function handle(): ServerResponse
{
    $lucky = $this->findTodayLucky();

    if ($lucky !== null) {
        return $this->sendText($this->lang('telegram.pidar-already-exists', [
            'username' => "@{$lucky->username}",
        ]));
    }

    $candidates = $this->chat->users()->get();

    if (! $candidates->find($this->sender->id) instanceof User) {
        $this->chat->users()->attach($this->sender);

        $this->sendText($this->lang('telegram.pidar-triggered-not-registered'));

        $candidates->push($this->sender);
    }

    $lucky = $this->chooseTodayLucky($candidates);

    $messages = app(\App\Services\PidarMessageService::class)->generate();

    $this->sendText($messages['start'] ?? $this->lang('telegram.pidar-start'));

    $this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));

    $this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

    $result = isset($messages['result'])
        ? str_replace(':username', "@{$lucky->username}", $messages['result'])
        : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

    return $this->sendText($result);
}
```

- [ ] **Step 2.2: Run the full test suite to confirm no regressions**

```bash
./run php artisan test
```

Expected: all existing tests PASS

- [ ] **Step 2.3: Commit**

```bash
git add app/Telegram/Commands/PidarCommand.php
git commit -m "feat: use PidarMessageService in PidarCommand with lang() fallback"
```

---

## Task 3: Integrate `PidarMessageService` into `AdhocPidarCommand`

**Files:**
- Modify: `app/Telegram/Commands/AdhocPidarCommand.php`

---

- [ ] **Step 3.1: Replace the message-sending block in `call()`**

Open `app/Telegram/Commands/AdhocPidarCommand.php`. Replace the five `sendText` calls in `call()` (lines 41–51) with the following:

```php
$messages = app(\App\Services\PidarMessageService::class)->generate(withAutomatedTrigger: true);

$this->sendText($messages['automated_trigger'] ?? $this->lang('telegram.pidar-automated-trigger'));

$this->sendText($messages['start'] ?? $this->lang('telegram.pidar-start'));

$this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));

$this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

$result = isset($messages['result'])
    ? str_replace(':username', "@{$lucky->username}", $messages['result'])
    : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

$this->sendText($result);
```

The full `call()` method after the change:

```php
public function call(): void
{
    if ($this->findTodayLucky() !== null) {
        return;
    }

    $candidates = $this->chat->users()->get();

    $lucky = $this->chooseTodayLucky($candidates);

    $messages = app(\App\Services\PidarMessageService::class)->generate(withAutomatedTrigger: true);

    $this->sendText($messages['automated_trigger'] ?? $this->lang('telegram.pidar-automated-trigger'));

    $this->sendText($messages['start'] ?? $this->lang('telegram.pidar-start'));

    $this->sendText($messages['step_1'] ?? $this->lang('telegram.pidar-step-1'));

    $this->sendText($messages['step_2'] ?? $this->lang('telegram.pidar-step-2'));

    $result = isset($messages['result'])
        ? str_replace(':username', "@{$lucky->username}", $messages['result'])
        : $this->lang('telegram.pidar-result', ['username' => "@{$lucky->username}"]);

    $this->sendText($result);
}
```

- [ ] **Step 3.2: Run the full test suite to confirm no regressions**

```bash
./run php artisan test
```

Expected: all tests PASS

- [ ] **Step 3.3: Commit**

```bash
git add app/Telegram/Commands/AdhocPidarCommand.php
git commit -m "feat: use PidarMessageService in AdhocPidarCommand with lang() fallback"
```
