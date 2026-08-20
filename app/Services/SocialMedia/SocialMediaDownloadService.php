<?php

namespace App\Services\SocialMedia;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class SocialMediaDownloadService
{
    private const int PROCESS_TIMEOUT_SECONDS = 45;
    private const int THUMBNAIL_FETCH_TIMEOUT_SECONDS = 10;
    private const int MAX_THUMBNAIL_REDIRECTS = 5;
    private const int MAX_THUMBNAIL_BYTES = 10 * 1024 * 1024;
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
                '--max-filesize', '48M',
                $link->url,
            ]);
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            Log::error('SocialMediaDownloadService: yt-dlp process failed', [
                'url' => $link->url,
                'error' => $exception->getMessage(),
            ]);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        if ($result->failed()) {
            File::deleteDirectory($scratchDir);

            Log::warning('SocialMediaDownloadService: yt-dlp exited with an error', [
                'url' => $link->url,
                'exit_code' => $result->exitCode(),
                'stderr' => Str::limit(trim($result->errorOutput()), 500),
            ]);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        $entries = $this->parseEntries($result->output());

        try {
            $resources = $this->buildResources($scratchDir, $entries, $link->url);
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            Log::error('SocialMediaDownloadService: failed to process downloaded media', [
                'url' => $link->url,
                'error' => $exception->getMessage(),
            ]);

            throw new SocialMediaDownloadException('не вдалося обробити завантажені медіа');
        }

        if ($resources === []) {
            File::deleteDirectory($scratchDir);

            Log::warning('SocialMediaDownloadService: no usable resources after filtering', [
                'url' => $link->url,
                'entries' => count($entries),
            ]);

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
    private function buildResources(string $scratchDir, array $entries, string $url): array
    {
        $resources = [];

        foreach ($entries as $entry) {
            $filename = $this->expectedFilename($entry);

            if ($filename === null) {
                Log::warning('SocialMediaDownloadService: skipping entry with unsafe filename', [
                    'url' => $url,
                    'entry_id' => $entry['id'] ?? null,
                ]);

                continue;
            }

            $path = $this->resolveContainedPath($scratchDir, $filename);

            if ($path === null) {
                Log::warning('SocialMediaDownloadService: skipping entry, file not found in scratch dir', [
                    'url' => $url,
                    'filename' => $filename,
                ]);

                continue;
            }

            if (File::size($path) > self::MAX_UPLOAD_BYTES) {
                Log::warning('SocialMediaDownloadService: skipping entry, file exceeds upload limit', [
                    'url' => $url,
                    'filename' => $filename,
                    'bytes' => File::size($path),
                ]);

                continue;
            }

            $type = $this->resourceTypeForExtension($entry['ext'] ?? '');

            $thumbnailPath = $path;

            if ($type === SocialMediaResourceType::Video) {
                $thumbnailPath = $this->downloadThumbnail($scratchDir, $entry['thumbnail'] ?? null);

                if ($thumbnailPath === null) {
                    Log::warning('SocialMediaDownloadService: skipping video, thumbnail unavailable', [
                        'url' => $url,
                        'filename' => $filename,
                    ]);

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
     * Builds the filename yt-dlp was told to write via the -o template, rejecting any
     * id/playlist_index/ext value that could escape the scratch directory.
     *
     * @param array<string, mixed> $entry
     */
    private function expectedFilename(array $entry): ?string
    {
        $id = (string) ($entry['id'] ?? '');
        $playlistIndex = (string) ($entry['playlist_index'] ?? 0);
        $ext = (string) ($entry['ext'] ?? '');

        foreach ([$id, $playlistIndex, $ext] as $component) {
            if ($component === '') {
                return null;
            }

            if (str_contains($component, '/') || str_contains($component, '\\') || str_contains($component, '..')) {
                return null;
            }
        }

        return "{$id}_{$playlistIndex}.{$ext}";
    }

    /**
     * Resolves the file to its canonical path and confirms it still lives inside
     * $scratchDir, as a second, independent guard against path traversal.
     */
    private function resolveContainedPath(string $scratchDir, string $filename): ?string
    {
        $realScratchDir = realpath($scratchDir);
        $realPath = realpath($scratchDir . '/' . $filename);

        if ($realScratchDir === false || $realPath === false) {
            return null;
        }

        if (! str_starts_with($realPath, $realScratchDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realPath;
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
            Log::warning('SocialMediaDownloadService: yt-dlp did not provide a thumbnail url');

            return null;
        }

        $bytes = $this->fetchThumbnailBytes($thumbnailUrl);

        if ($bytes === null) {
            return null;
        }

        $path = $scratchDir . '/' . Str::uuid()->toString() . '_thumb.jpg';

        File::put($path, $bytes);

        return $path;
    }

    /**
     * Fetches thumbnail bytes with SSRF protections: each hop (initial URL and any
     * redirect target) is scheme/host/IP-validated before it's requested, and the
     * connection is pinned (via CURLOPT_RESOLVE) to the exact IP that was validated
     * so a second, unvalidated DNS lookup at connect time can't be used to rebind
     * the hostname to an internal address after validation passes. Redirects are
     * followed manually up to a fixed cap, the response body is read in bounded
     * chunks instead of buffered whole, and the Content-Type must look like an image.
     */
    private function fetchThumbnailBytes(string $url): ?string
    {
        $thumbnailUrl = $url;

        for ($hop = 0; $hop <= self::MAX_THUMBNAIL_REDIRECTS; $hop++) {
            $pinnedIp = $this->resolveSafeConnectIp($url);

            if ($pinnedIp === null) {
                return null;
            }

            $resolveEntry = $this->curlResolveEntry($url, $pinnedIp);

            if ($resolveEntry === null) {
                Log::warning('SocialMediaDownloadService: thumbnail url malformed for curl resolve', [
                    'thumbnail_url' => $thumbnailUrl,
                    'hop_url' => $url,
                ]);

                return null;
            }

            try {
                $response = Http::timeout(self::THUMBNAIL_FETCH_TIMEOUT_SECONDS)
                    ->withoutRedirecting()
                    ->withOptions([
                        'stream' => true,
                        'curl' => [CURLOPT_RESOLVE => [$resolveEntry]],
                    ])
                    ->get($url);
            } catch (Throwable $exception) {
                Log::warning('SocialMediaDownloadService: thumbnail request failed', [
                    'thumbnail_url' => $thumbnailUrl,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if (! is_string($location) || ! preg_match('#^https?://#i', $location)) {
                    Log::warning('SocialMediaDownloadService: thumbnail redirected to an invalid location', [
                        'thumbnail_url' => $thumbnailUrl,
                        'location' => $location,
                    ]);

                    return null;
                }

                $url = $location;

                continue;
            }

            if (! $response->successful()) {
                Log::warning('SocialMediaDownloadService: thumbnail request returned an error status', [
                    'thumbnail_url' => $thumbnailUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            if (! str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/')) {
                Log::warning('SocialMediaDownloadService: thumbnail response is not an image', [
                    'thumbnail_url' => $thumbnailUrl,
                    'content_type' => $response->header('Content-Type'),
                ]);

                return null;
            }

            return $this->readBoundedBody($response, self::MAX_THUMBNAIL_BYTES, $thumbnailUrl);
        }

        Log::warning('SocialMediaDownloadService: thumbnail exceeded max redirects', [
            'thumbnail_url' => $thumbnailUrl,
        ]);

        return null;
    }

    private function readBoundedBody(Response $response, int $maxBytes, string $thumbnailUrl): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';

        while (! $stream->eof()) {
            $bytes .= $stream->read(8192);

            if (strlen($bytes) > $maxBytes) {
                Log::warning('SocialMediaDownloadService: thumbnail exceeded max size', [
                    'thumbnail_url' => $thumbnailUrl,
                    'max_bytes' => $maxBytes,
                ]);

                return null;
            }
        }

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Validates the URL's scheme and resolves+validates its host, returning the
     * single IP the connection must be pinned to. Returning the IP (rather than a
     * bool) lets the caller force the actual HTTP connection onto exactly the
     * address that was validated here, instead of trusting a second DNS lookup
     * performed later by cURL at connect time.
     */
    private function resolveSafeConnectIp(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            Log::warning('SocialMediaDownloadService: thumbnail url is malformed', ['thumbnail_url' => $url]);

            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            Log::warning('SocialMediaDownloadService: thumbnail url has unsupported scheme', [
                'thumbnail_url' => $url,
                'scheme' => $parts['scheme'],
            ]);

            return null;
        }

        return $this->resolveSafeIp($parts['host']);
    }

    private function resolveSafeIp(string $host): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveHostIps($host);

        if ($ips === []) {
            Log::warning('SocialMediaDownloadService: thumbnail host did not resolve', ['host' => $host]);

            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                Log::warning('SocialMediaDownloadService: thumbnail host resolved to a blocked ip', [
                    'host' => $host,
                    'ip' => $ip,
                ]);

                return null;
            }
        }

        return $ips[0];
    }

    /**
     * Builds a CURLOPT_RESOLVE entry ("host:port:ip") that pins the connection for
     * $url's host+port to the already-validated $ip, bypassing cURL's own DNS
     * resolution at connect time (the DNS-rebinding vector this guards against).
     */
    private function curlResolveEntry(string $url, string $ip): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $host = str_contains($parts['host'], ':') ? "[{$parts['host']}]" : $parts['host'];

        return "{$host}:{$port}:{$ip}";
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        $ipv4Records = @gethostbynamel($host);

        if (is_array($ipv4Records)) {
            array_push($ips, ...$ipv4Records);
        }

        $ipv6Records = @dns_get_record($host, DNS_AAAA);

        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        if ($this->isCgnatIp($ip)) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE don't cover RFC 6598 carrier-grade NAT
     * space (100.64.0.0/10), which some cloud/K8s internal services use.
     */
    private function isCgnatIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        return $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255');
    }
}
