<?php

namespace Tests\Feature\Telegram\Commands;

use App\Models\Chat;
use App\Services\OpenAIService;
use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaResource;
use App\Services\SocialMedia\SocialMediaResourceType;
use App\Telegram\Commands\GenericmessageCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenericmessageCommandSocialMediaTest extends TestCase
{
    private array $requestHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $responses = array_fill(0, 10, new Response(200, [], json_encode(['ok' => true, 'result' => true])));
        $handlerStack = HandlerStack::create(new MockHandler($responses));

        $this->requestHistory = [];
        $handlerStack->push(Middleware::history($this->requestHistory));

        Request::setClient(new Client(['handler' => $handlerStack]));
    }

    protected function tearDown(): void
    {
        Request::setClient(new Client());
        Mockery::close();
        parent::tearDown();
    }

    public function test_replies_with_photo_and_caption_when_link_download_succeeds(): void
    {
        $photoPath = $this->makeJpegFixture();

        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Photo,
            path: $photoPath,
            thumbnailPath: $photoPath,
            title: 'Funny cat',
            description: null,
        );

        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldReceive('download')->once()->andReturn([$resource]);
        $downloader->shouldReceive('cleanup')->once()->with([$resource]);
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')->once()->andReturn('Дивись, який кіт!');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('глянь https://twitter.com/user/status/999999');

        $command->handle();

        unlink($photoPath);

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendPhoto', (string) $request->getUri());

        $body = (string) $request->getBody();
        $this->assertStringContainsString('name="caption"', $body);
        $this->assertStringContainsString('Дивись, який кіт!', $body);
        $this->assertStringContainsString('name="reply_parameters"', $body);
    }

    public function test_replies_with_text_only_when_link_download_fails(): void
    {
        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldReceive('download')
            ->once()
            ->andThrow(new SocialMediaDownloadException('приватний акаунт'));
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'приватний акаунт'))
            ->andReturn('Ой, не вийшло, соррі.');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('глянь https://twitter.com/user/status/888888');

        $command->handle();

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendMessage', (string) $request->getUri());
        $this->assertStringContainsString('Ой, не вийшло, соррі.', urldecode((string) $request->getBody()));
    }

    public function test_replies_with_photo_when_link_is_in_the_replied_to_message(): void
    {
        $photoPath = $this->makeJpegFixture();

        $resource = new SocialMediaResource(
            type: SocialMediaResourceType::Photo,
            path: $photoPath,
            thumbnailPath: $photoPath,
            title: 'Funny cat',
            description: null,
        );

        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldReceive('download')->once()->andReturn([$resource]);
        $downloader->shouldReceive('cleanup')->once()->with([$resource]);
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')->once()->andReturn('Дивись, який кіт!');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('', [
            'message_id' => 41,
            'date' => 1700000000,
            'chat' => ['id' => 555, 'type' => 'group', 'title' => 'Test Chat'],
            'from' => ['id' => 888, 'is_bot' => false, 'first_name' => 'Other', 'username' => 'other_user'],
            'text' => 'глянь https://twitter.com/user/status/999999',
        ]);

        $command->handle();

        unlink($photoPath);

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendPhoto', (string) $request->getUri());

        $body = (string) $request->getBody();
        $this->assertStringContainsString('"message_id":41', $body);
    }

    public function test_falls_through_to_existing_ai_flow_when_no_link_present(): void
    {
        $downloader = Mockery::mock(SocialMediaDownloadService::class);
        $downloader->shouldNotReceive('download');
        $this->app->instance(SocialMediaDownloadService::class, $downloader);

        $openai = Mockery::mock(OpenAIService::class);
        $openai->shouldReceive('generateResponse')->once()->andReturn('Просто відповідь.');
        $this->app->instance(OpenAIService::class, $openai);

        $command = $this->makeCommand('привіт, як справи?');

        $command->handle();

        $request = $this->lastRequest();
        $this->assertStringContainsString('sendMessage', (string) $request->getUri());
        $this->assertStringContainsString('Просто відповідь.', urldecode((string) $request->getBody()));
    }

    private function makeCommand(string $text, ?array $replyToMessage = null): GenericmessageCommand
    {
        $telegram = new Telegram('111111:test-api-key-xxxxxxxxxxxxxxxxxxxxxxxxxxx', 'test_bot');

        $message = [
            'message_id' => 42,
            'date' => 1700000000,
            'chat' => ['id' => 555, 'type' => 'group', 'title' => 'Test Chat'],
            'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Tester', 'username' => 'tester_user'],
            'text' => trim("@test_bot {$text}"),
        ];

        if ($replyToMessage !== null) {
            $message['reply_to_message'] = $replyToMessage;
        }

        $update = new Update([
            'update_id' => 1,
            'message' => $message,
        ], 'test_bot');

        $command = new GenericmessageCommand($telegram, $update);

        $chat = new Chat(['tg_id' => 555, 'title' => 'Test Chat', 'type' => 'group']);
        $chat->id = 1;
        $command->setChat($chat);

        return $command;
    }

    private function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        if ($this->requestHistory === []) {
            throw new RuntimeException('No Telegram API request was captured.');
        }

        return end($this->requestHistory)['request'];
    }

    private function makeJpegFixture(): string
    {
        $path = sys_get_temp_dir() . '/social-media-command-test-' . uniqid() . '.jpg';
        $image = imagecreatetruecolor(2, 2);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }
}
