<?php

namespace App\Services\SocialMedia;

final readonly class SocialMediaLink
{
    // Positive RFC 3986 URL-safe whitelist, not a "not whitespace" negation: stops the match at
    // ANY unsafe byte, including non-ASCII/invisible separators like zero-width space or NBSP,
    // which negated \s classes let through and an attacker could use to smuggle a second URL in.
    private const string URL_SAFE_CHARS = 'A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%';

    private const string URL_SAFE_CHARS_NO_SLASH = 'A-Za-z0-9\-._~:?#\[\]@!$&\'()*+,;=%';

    // Same whitelist minus the sentence/bracket punctuation that shouldn't trail a URL, used as
    // the final matched character so trailing "." ")" etc. aren't captured into the URL.
    private const string URL_SAFE_TRAILING_CHAR = 'A-Za-z0-9\-_~:\/#\[@$&(*+;=%';

    private const array PATTERNS = [
        'twitter' => '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/['.self::URL_SAFE_CHARS_NO_SLASH.']+\/status\/\d+(?:['.self::URL_SAFE_CHARS.']*['.self::URL_SAFE_TRAILING_CHAR.'])?/i',
        'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel|tv)\/[A-Za-z0-9_-]+(?:['.self::URL_SAFE_CHARS.']*['.self::URL_SAFE_TRAILING_CHAR.'])?/i',
        'tiktok' => '/https?:\/\/(?:(?:www\.)?tiktok\.com\/@['.self::URL_SAFE_CHARS_NO_SLASH.']+\/video\/\d+|v[mt]\.tiktok\.com\/(?:['.self::URL_SAFE_CHARS.']*['.self::URL_SAFE_TRAILING_CHAR.']))/i',
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
