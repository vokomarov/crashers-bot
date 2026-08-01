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

            Log::error('SocialMediaDownloadService: yt-dlp process failed', ['error' => $exception->getMessage()]);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        if ($result->failed()) {
            File::deleteDirectory($scratchDir);

            throw new SocialMediaDownloadException('не вдалося завантажити медіа за посиланням');
        }

        try {
            $resources = $this->buildResources($scratchDir, $this->parseEntries($result->output()));
        } catch (Throwable $exception) {
            File::deleteDirectory($scratchDir);

            Log::error('SocialMediaDownloadService: failed to process downloaded media', ['error' => $exception->getMessage()]);

            throw new SocialMediaDownloadException('не вдалося обробити завантажені медіа');
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
            $filename = $this->expectedFilename($entry);

            if ($filename === null) {
                continue;
            }

            $path = $this->resolveContainedPath($scratchDir, $filename);

            if ($path === null) {
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
        for ($hop = 0; $hop <= self::MAX_THUMBNAIL_REDIRECTS; $hop++) {
            $pinnedIp = $this->resolveSafeConnectIp($url);

            if ($pinnedIp === null) {
                return null;
            }

            $resolveEntry = $this->curlResolveEntry($url, $pinnedIp);

            if ($resolveEntry === null) {
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
            } catch (Throwable) {
                return null;
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if (! is_string($location) || ! preg_match('#^https?://#i', $location)) {
                    return null;
                }

                $url = $location;

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            if (! str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/')) {
                return null;
            }

            return $this->readBoundedBody($response, self::MAX_THUMBNAIL_BYTES);
        }

        return null;
    }

    private function readBoundedBody(Response $response, int $maxBytes): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';

        while (! $stream->eof()) {
            $bytes .= $stream->read(8192);

            if (strlen($bytes) > $maxBytes) {
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
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
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
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
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
