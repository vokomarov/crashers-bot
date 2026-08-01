<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaPlatform;
use App\Services\SocialMedia\SocialMediaResourceType;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class SocialMediaDownloadServiceTest extends TestCase
{
    private SocialMediaDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SocialMediaDownloadService();
    }

    public function test_downloads_single_photo(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/1111_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '1111',
                'ext' => 'jpg',
                'title' => 'A nice photo',
                'description' => 'Just a photo',
                'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/1111');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Photo, $resources[0]->type);
        $this->assertSame('A nice photo', $resources[0]->title);
        $this->assertSame('Just a photo', $resources[0]->description);
        $this->assertSame($resources[0]->path, $resources[0]->thumbnailPath);
        $this->assertFileExists($resources[0]->path);
    }

    public function test_downloads_single_video_and_its_thumbnail(): void
    {
        $thumbnailSourcePath = sys_get_temp_dir() . '/social-media-thumb-fixture-' . uniqid() . '.jpg';
        File::put($thumbnailSourcePath, 'fake-thumb-bytes');

        Process::fake(function (PendingProcess $process) use ($thumbnailSourcePath) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/2222_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '2222',
                'ext' => 'mp4',
                'title' => 'A nice video',
                'description' => null,
                'thumbnail' => $thumbnailSourcePath,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::TikTok, 'https://www.tiktok.com/@user/video/2222');

        $resources = $this->service->download($link);

        File::delete($thumbnailSourcePath);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Video, $resources[0]->type);
        $this->assertFileExists($resources[0]->thumbnailPath);
        $this->assertNotSame($resources[0]->path, $resources[0]->thumbnailPath);
    }

    public function test_excludes_video_entry_with_no_fetchable_thumbnail_from_results(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/5555_1.jpg", 'fake-jpeg-bytes');
            File::put("{$scratchDir}/5555_2.mp4", 'fake-mp4-bytes');

            $entries = [
                ['id' => '5555', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => 'Photo', 'description' => null, 'thumbnail' => null],
                ['id' => '5555', 'ext' => 'mp4', 'playlist_index' => 2, 'title' => 'Video without thumbnail', 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/DEF456/');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Photo, $resources[0]->type);
    }

    public function test_downloads_multi_item_carousel(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/3333_1.jpg", 'fake-jpeg-1');
            File::put("{$scratchDir}/3333_2.jpg", 'fake-jpeg-2');

            $entries = [
                ['id' => '3333', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => 'Carousel', 'description' => null, 'thumbnail' => null],
                ['id' => '3333', 'ext' => 'jpg', 'playlist_index' => 2, 'title' => 'Carousel', 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/ABC123/');

        $resources = $this->service->download($link);

        $this->assertCount(2, $resources);
    }

    public function test_wraps_resource_building_failures_and_cleans_up_scratch_dir(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $scratchDir = $this->scratchDirFromCommand($process->command);
            $capturedScratchDir = $scratchDir;

            File::put("{$scratchDir}/6666_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '6666',
                'ext' => 'jpg',
                'title' => 'A nice photo',
                'description' => null,
                'thumbnail' => null,
            ]) . "\n");
        });

        File::partialMock()
            ->shouldReceive('size')
            ->andThrow(new RuntimeException('disk read error'));

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/6666');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertStringContainsString('disk read error', $exception->getMessage());
        }

        $this->assertNotNull($capturedScratchDir);
        $this->assertDirectoryDoesNotExist($capturedScratchDir);
    }

    private function scratchDirFromCommand(array $command): string
    {
        $outputTemplate = $command[array_search('-o', $command, true) + 1];

        return dirname($outputTemplate);
    }
}
