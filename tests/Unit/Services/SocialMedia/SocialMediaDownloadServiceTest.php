<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaDownloadException;
use App\Services\SocialMedia\SocialMediaDownloadService;
use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaPlatform;
use App\Services\SocialMedia\SocialMediaResourceType;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SocialMediaDownloadServiceTest extends TestCase
{
    private SocialMediaDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SocialMediaDownloadService();

        // Fail loud instead of silently hitting the real network if a test
        // forgets to fake a thumbnail URL it exercises.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
        Http::assertNothingSent();
    }

    public function test_downloads_single_video_and_its_thumbnail(): void
    {
        Http::fake([
            'http://8.8.8.8/thumb.jpg' => Http::response('fake-thumb-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/2222_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '2222',
                'ext' => 'mp4',
                'title' => 'A nice video',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/thumb.jpg',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::TikTok, 'https://www.tiktok.com/@user/video/2222');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame(SocialMediaResourceType::Video, $resources[0]->type);
        $this->assertFileExists($resources[0]->thumbnailPath);
        $this->assertNotSame($resources[0]->path, $resources[0]->thumbnailPath);
        $this->assertSame('fake-thumb-bytes', File::get($resources[0]->thumbnailPath));
    }

    /**
     * Guards against DNS-rebinding: validating a hostname's resolved IP is
     * worthless if the actual connection later performs its own, separate DNS
     * lookup (an attacker could return a safe IP to validation and an internal
     * one to the real connect a moment later). The fix pins the connection to
     * the exact IP that was validated via CURLOPT_RESOLVE, so this asserts that
     * option is present on the outbound request and points at the validated IP.
     */
    public function test_pins_thumbnail_connection_to_the_dns_validated_ip(): void
    {
        $capturedCurlOptions = null;

        Http::fake(function ($request, $options) use (&$capturedCurlOptions) {
            $capturedCurlOptions = $options['curl'] ?? null;

            return Http::response('fake-thumb-bytes', 200, ['Content-Type' => 'image/jpeg']);
        });

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/1313_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '1313',
                'ext' => 'mp4',
                'title' => 'Video with pinned thumbnail fetch',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/thumb.jpg',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/1313');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertIsArray($capturedCurlOptions);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $capturedCurlOptions);
        $this->assertSame(['8.8.8.8:80:8.8.8.8'], $capturedCurlOptions[CURLOPT_RESOLVE]);
    }

    /**
     * Same rebinding guard as above, but across a redirect hop: the pin must be
     * rebuilt for the redirect target's own host, not reused from the first hop.
     */
    public function test_pins_each_redirect_hop_to_its_own_validated_ip(): void
    {
        $capturedCurlOptionsByUrl = [];

        Http::fake(function ($request, $options) use (&$capturedCurlOptionsByUrl) {
            $capturedCurlOptionsByUrl[$request->url()] = $options['curl'] ?? null;

            if ($request->url() === 'http://8.8.8.8/redirect') {
                return Http::response('', 302, ['Location' => 'http://1.1.1.1/thumb.jpg']);
            }

            return Http::response('fake-thumb-bytes', 200, ['Content-Type' => 'image/jpeg']);
        });

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/1414_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '1414',
                'ext' => 'mp4',
                'title' => 'Video with redirecting, pinned thumbnail fetch',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/redirect',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/1414');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame(
            ['8.8.8.8:80:8.8.8.8'],
            $capturedCurlOptionsByUrl['http://8.8.8.8/redirect'][CURLOPT_RESOLVE] ?? null,
        );
        $this->assertSame(
            ['1.1.1.1:80:1.1.1.1'],
            $capturedCurlOptionsByUrl['http://1.1.1.1/thumb.jpg'][CURLOPT_RESOLVE] ?? null,
        );
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
        Http::assertNothingSent();
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeThumbnailUrlProvider(): array
    {
        return [
            'non-http scheme' => ['file:///etc/passwd'],
            'loopback' => ['http://127.0.0.1/secret'],
            'cloud metadata link-local' => ['http://169.254.169.254/latest/meta-data/'],
            'private RFC1918 range' => ['http://10.0.0.5/internal'],
            'RFC6598 carrier-grade NAT range' => ['http://100.64.0.1/internal'],
        ];
    }

    /**
     * @dataProvider unsafeThumbnailUrlProvider
     */
    public function test_rejects_unsafe_thumbnail_urls_without_making_a_request(string $unsafeUrl): void
    {
        Process::fake(function (PendingProcess $process) use ($unsafeUrl) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/7777_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '7777',
                'ext' => 'mp4',
                'title' => 'Video with unsafe thumbnail',
                'description' => null,
                'thumbnail' => $unsafeUrl,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/7777');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException) {
            // Expected: the only entry is a video whose thumbnail could not be
            // safely fetched, so buildResources() ends up empty.
        }

        Http::assertNothingSent();
    }

    public function test_follows_safe_redirect_to_fetch_thumbnail(): void
    {
        Http::fake([
            'http://8.8.8.8/redirect' => Http::response('', 302, ['Location' => 'http://1.1.1.1/thumb.jpg']),
            'http://1.1.1.1/thumb.jpg' => Http::response('fake-thumb-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/8888_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '8888',
                'ext' => 'mp4',
                'title' => 'Video with redirecting thumbnail',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/redirect',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/8888');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame('fake-thumb-bytes', File::get($resources[0]->thumbnailPath));
    }

    public function test_blocks_redirect_to_unsafe_thumbnail_target(): void
    {
        Http::fake([
            'http://8.8.8.8/redirect-to-metadata' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/9999_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '9999',
                'ext' => 'mp4',
                'title' => 'Video redirecting to metadata endpoint',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/redirect-to-metadata',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/9999');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException) {
            // Expected.
        }

        Http::assertSentCount(1);
    }

    public function test_rejects_thumbnail_response_with_non_image_content_type(): void
    {
        Http::fake([
            'http://8.8.8.8/thumb.jpg' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/1234_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '1234',
                'ext' => 'mp4',
                'title' => 'Video with html thumbnail response',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/thumb.jpg',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/1234');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException) {
            Http::assertSentCount(1);
        }
    }

    public function test_rejects_thumbnail_response_exceeding_size_cap(): void
    {
        $oversized = str_repeat('a', 10 * 1024 * 1024 + 1);

        Http::fake([
            'http://8.8.8.8/huge-thumb.jpg' => Http::response($oversized, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/4321_0.mp4", 'fake-mp4-bytes');

            return Process::result(json_encode([
                'id' => '4321',
                'ext' => 'mp4',
                'title' => 'Video with oversized thumbnail',
                'description' => null,
                'thumbnail' => 'http://8.8.8.8/huge-thumb.jpg',
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/4321');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException) {
            Http::assertSentCount(1);
        }
    }

    public function test_rejects_entry_with_path_traversal_in_id(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/9090_1.jpg", 'fake-jpeg-bytes');

            $entries = [
                ['id' => '../../../etc/passwd', 'ext' => 'jpg', 'playlist_index' => 0, 'title' => 'Malicious', 'description' => null, 'thumbnail' => null],
                ['id' => '9090', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => 'Legit photo', 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/GHI789/');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertSame('Legit photo', $resources[0]->title);
    }

    public function test_yt_dlp_command_includes_max_filesize_flag(): void
    {
        $capturedCommand = null;

        Process::fake(function (PendingProcess $process) use (&$capturedCommand) {
            $capturedCommand = $process->command;
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/6060_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '6060',
                'ext' => 'jpg',
                'title' => 'Photo',
                'description' => null,
                'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/6060');

        $this->service->download($link);

        $this->assertIsArray($capturedCommand);
        $flagIndex = array_search('--max-filesize', $capturedCommand, true);
        $this->assertNotFalse($flagIndex, '--max-filesize flag was not passed to yt-dlp.');
        $this->assertSame('48M', $capturedCommand[$flagIndex + 1]);
    }

    public function test_wraps_resource_building_failures_logs_details_and_hides_them_from_the_caller(): void
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

        Log::shouldReceive('error')
            ->once()
            ->with(
                'SocialMediaDownloadService: failed to process downloaded media',
                Mockery::on(fn (array $context) => str_contains($context['error'] ?? '', 'disk read error')),
            );

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/6666');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertStringNotContainsString('disk read error', $exception->getMessage());
            $this->assertSame('не вдалося обробити завантажені медіа', $exception->getMessage());
        }

        $this->assertNotNull($capturedScratchDir);
        $this->assertDirectoryDoesNotExist($capturedScratchDir);
    }

    public function test_throws_on_nonzero_exit_code_and_cleans_up_scratch_dir(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $capturedScratchDir = $this->scratchDirFromCommand($process->command);

            return Process::result(output: '', errorOutput: 'error: private post', exitCode: 1);
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/4444');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertDirectoryDoesNotExist($capturedScratchDir);
        }
    }

    public function test_throws_when_process_run_fails_unexpectedly_and_cleans_up_scratch_dir(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $capturedScratchDir = $this->scratchDirFromCommand($process->command);

            throw new RuntimeException('process could not start');
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/5555');

        try {
            $this->service->download($link);
            $this->fail('Expected SocialMediaDownloadException was not thrown.');
        } catch (SocialMediaDownloadException $exception) {
            $this->assertDirectoryDoesNotExist($capturedScratchDir);
        }
    }

    public function test_drops_oversized_item_but_keeps_others(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/6666_1.jpg", str_repeat('a', 51 * 1024 * 1024));
            File::put("{$scratchDir}/6666_2.jpg", 'small-file');

            $entries = [
                ['id' => '6666', 'ext' => 'jpg', 'playlist_index' => 1, 'title' => null, 'description' => null, 'thumbnail' => null],
                ['id' => '6666', 'ext' => 'jpg', 'playlist_index' => 2, 'title' => null, 'description' => null, 'thumbnail' => null],
            ];

            return Process::result(implode("\n", array_map('json_encode', $entries)) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/OVERSIZED/');

        $resources = $this->service->download($link);

        $this->assertCount(1, $resources);
        $this->assertStringEndsWith('6666_2.jpg', $resources[0]->path);
    }

    public function test_throws_when_all_items_are_oversized(): void
    {
        Process::fake(function (PendingProcess $process) {
            $scratchDir = $this->scratchDirFromCommand($process->command);

            File::put("{$scratchDir}/7777_0.jpg", str_repeat('a', 51 * 1024 * 1024));

            return Process::result(json_encode([
                'id' => '7777', 'ext' => 'jpg', 'title' => null, 'description' => null, 'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Instagram, 'https://instagram.com/p/TOOBIG/');

        $this->expectException(SocialMediaDownloadException::class);

        $this->service->download($link);
    }

    public function test_cleanup_deletes_scratch_directory(): void
    {
        $capturedScratchDir = null;

        Process::fake(function (PendingProcess $process) use (&$capturedScratchDir) {
            $scratchDir = $this->scratchDirFromCommand($process->command);
            $capturedScratchDir = $scratchDir;

            File::put("{$scratchDir}/8888_0.jpg", 'fake-jpeg-bytes');

            return Process::result(json_encode([
                'id' => '8888', 'ext' => 'jpg', 'title' => null, 'description' => null, 'thumbnail' => null,
            ]) . "\n");
        });

        $link = new SocialMediaLink(SocialMediaPlatform::Twitter, 'https://twitter.com/user/status/8888');

        $resources = $this->service->download($link);

        $this->assertDirectoryExists($capturedScratchDir);

        $this->service->cleanup($resources);

        $this->assertDirectoryDoesNotExist($capturedScratchDir);
    }

    private function scratchDirFromCommand(array $command): string
    {
        $outputTemplate = $command[array_search('-o', $command, true) + 1];

        return dirname($outputTemplate);
    }
}
