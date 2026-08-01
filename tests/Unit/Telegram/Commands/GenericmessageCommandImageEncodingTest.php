<?php

namespace Tests\Unit\Telegram\Commands;

use App\Telegram\Commands\GenericmessageCommand;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class GenericmessageCommandImageEncodingTest extends TestCase
{
    private GenericmessageCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new NullLogger());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $telegram = new Telegram('123456:test-api-key', 'test_bot');
        $this->command = new GenericmessageCommand($telegram);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_encode_image_bytes_to_data_uri_returns_jpeg_data_uri(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeImageBytesToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, $this->makeJpegBytes());

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    public function test_encode_image_bytes_to_data_uri_returns_null_for_invalid_bytes(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeImageBytesToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, 'not an image');

        $this->assertNull($dataUri);
    }

    public function test_encode_local_image_to_data_uri_reads_file_and_encodes_it(): void
    {
        $path = sys_get_temp_dir() . '/social-media-test-' . uniqid() . '.jpg';
        file_put_contents($path, $this->makeJpegBytes());

        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeLocalImageToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, $path);

        unlink($path);

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    public function test_encode_local_image_to_data_uri_returns_null_for_missing_file(): void
    {
        $method = new ReflectionMethod(GenericmessageCommand::class, 'encodeLocalImageToDataUri');
        $method->setAccessible(true);

        $dataUri = $method->invoke($this->command, '/nonexistent/path/to/file.jpg');

        $this->assertNull($dataUri);
    }

    private function makeJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
