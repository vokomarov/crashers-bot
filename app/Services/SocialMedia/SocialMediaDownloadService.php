<?php

namespace App\Services\SocialMedia;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class SocialMediaDownloadService
{
    private const int PROCESS_TIMEOUT_SECONDS = 45;
    private const int THUMBNAIL_FETCH_TIMEOUT_SECONDS = 10;
    private const int MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
    private const int MAX_RESOURCES = 10;
    private const array VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'mkv'];

    /**
     * @return array<int, SocialMediaResource>
     * @throws SocialMediaDownloadException
     */
    public function download(SocialMediaLink $link): array
    {
        $scratchDir = storage_path('app/tmp/social/' . Str::uuid()->toString());

        File::makeDirectory($scratchDir, 0755, true);

        try {
            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
                'yt-dlp',
                '--print-json',
                '--no-warnings',
                '-o', "{$scratchDir}/%(id)s_%(playlist_index|0)s.%(ext)s",
                '--format', 'best[filesize<48M]/best',
                $link->url,
            ]);
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException("не вдалося завантажити медіа: {$exception->getMessage()}");
        }

        if ($result->failed()) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        try {
            $resources = $this->buildResources($scratchDir, $this->parseEntries($result->output()));
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException("не вдалося обробити завантажені медіа: {$exception->getMessage()}");
        }

        if ($resources === []) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException('усі файли за посиланням виявились завеликими або відсутніми');
        }

        return array_slice($resources, 0, self::MAX_RESOURCES);
    }

    /**
     * @param array<int, SocialMediaResource> $resources
     */
    public function cleanup(array $resources): void
    {
        File::deleteDirectory(dirname($resources[0]->path));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseEntries(string $output): array
    {
        $entries = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (! is_array($entry)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, SocialMediaResource>
     */
    private function buildResources(string $scratchDir, array $entries): array
    {
        $resources = [];

        foreach ($entries as $entry) {
            $path = $scratchDir . '/' . $this->expectedFilename($entry);

            if (! File::exists($path)) {
                continue;
            }

            if (File::size($path) > self::MAX_UPLOAD_BYTES) {
                continue;
            }

            $type = $this->resourceTypeForExtension($entry['ext'] ?? '');

            $thumbnailPath = $path;

            if ($type === SocialMediaResourceType::Video) {
                $thumbnailPath = $this->downloadThumbnail($scratchDir, $entry['thumbnail'] ?? null);

                if ($thumbnailPath === null) {
                    continue;
                }
            }

            $resources[] = new SocialMediaResource(
                type: $type,
                path: $path,
                thumbnailPath: $thumbnailPath,
                title: $entry['title'] ?? null,
                description: $entry['description'] ?? null,
            );
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function expectedFilename(array $entry): string
    {
        $id = $entry['id'] ?? '';
        $playlistIndex = $entry['playlist_index'] ?? 0;
        $ext = $entry['ext'] ?? '';

        return "{$id}_{$playlistIndex}.{$ext}";
    }

    private function resourceTypeForExtension(string $extension): SocialMediaResourceType
    {
        return in_array(strtolower($extension), self::VIDEO_EXTENSIONS, true)
            ? SocialMediaResourceType::Video
            : SocialMediaResourceType::Photo;
    }

    private function downloadThumbnail(string $scratchDir, ?string $thumbnailUrl): ?string
    {
        if ($thumbnailUrl === null) {
            return null;
        }

        $context = stream_context_create([
            'http' => ['timeout' => self::THUMBNAIL_FETCH_TIMEOUT_SECONDS],
        ]);

        $bytes = @file_get_contents($thumbnailUrl, false, $context);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $path = $scratchDir . '/' . Str::uuid()->toString() . '_thumb.jpg';

        File::put($path, $bytes);

        return $path;
    }
}
