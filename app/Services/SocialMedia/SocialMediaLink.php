<?php

namespace App\Services\SocialMedia;

final readonly class SocialMediaLink
{
    // Trailing group excludes sentence/bracket punctuation so it isn't captured into the URL.
    private const array PATTERNS = [
        'twitter' => '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/[^\/\s]+\/status\/\d+(?:[^\s]*[^\s.,!?)\]}\'"])?/i',
        'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel|tv)\/[A-Za-z0-9_-]+(?:[^\s]*[^\s.,!?)\]}\'"])?/i',
        'tiktok' => '/https?:\/\/(?:(?:www\.)?tiktok\.com\/@[^\/\s]+\/video\/\d+|v[mt]\.tiktok\.com\/(?:[^\s]*[^\s.,!?)\]}\'"]))/i',
    ];

    public function __construct(
        public SocialMediaPlatform $platform,
        public string $url,
    ) {
    }

    public static function findIn(string $text): ?self
    {
        $earliestPlatform = null;
        $earliestUrl = null;
        $earliestOffset = null;

        foreach (self::PATTERNS as $platformValue => $pattern) {
            if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            [$url, $offset] = $matches[0];

            if ($earliestOffset !== null && $offset >= $earliestOffset) {
                continue;
            }

            $earliestPlatform = SocialMediaPlatform::from($platformValue);
            $earliestUrl = $url;
            $earliestOffset = $offset;
        }

        if ($earliestPlatform === null || $earliestUrl === null) {
            return null;
        }

        return new self($earliestPlatform, $earliestUrl);
    }
}
