<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaLink;
use App\Services\SocialMedia\SocialMediaPlatform;
use PHPUnit\Framework\TestCase;

class SocialMediaLinkTest extends TestCase
{
    public function test_matches_twitter_status_url(): void
    {
        $link = SocialMediaLink::findIn('check this out https://twitter.com/someuser/status/1234567890');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Twitter, $link->platform);
        $this->assertSame('https://twitter.com/someuser/status/1234567890', $link->url);
    }

    public function test_matches_x_status_url(): void
    {
        $link = SocialMediaLink::findIn('https://x.com/someuser/status/1234567890');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Twitter, $link->platform);
    }

    public function test_matches_instagram_post_url(): void
    {
        $link = SocialMediaLink::findIn('look https://www.instagram.com/p/Cabc123XYZ/ nice');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Instagram, $link->platform);
    }

    public function test_matches_instagram_reel_url(): void
    {
        $link = SocialMediaLink::findIn('https://instagram.com/reel/Cabc123XYZ/');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::Instagram, $link->platform);
    }

    public function test_matches_tiktok_video_url(): void
    {
        $link = SocialMediaLink::findIn('https://www.tiktok.com/@someuser/video/1234567890123456789');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }

    public function test_matches_tiktok_short_url(): void
    {
        $link = SocialMediaLink::findIn('https://vm.tiktok.com/ABC123/');

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }

    public function test_ignores_twitter_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://twitter.com/someuser'));
    }

    public function test_ignores_instagram_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://instagram.com/someuser'));
    }

    public function test_ignores_tiktok_profile_url(): void
    {
        $this->assertNull(SocialMediaLink::findIn('https://www.tiktok.com/@someuser'));
    }

    public function test_returns_null_when_no_link_present(): void
    {
        $this->assertNull(SocialMediaLink::findIn('just a normal message with no links'));
    }

    public function test_returns_first_match_when_multiple_links_present(): void
    {
        $text = 'first https://www.tiktok.com/@someuser/video/1234567890123456789 '
            . 'then https://twitter.com/someuser/status/1234567890';

        $link = SocialMediaLink::findIn($text);

        $this->assertNotNull($link);
        $this->assertSame(SocialMediaPlatform::TikTok, $link->platform);
    }

    public function test_strips_trailing_period_from_twitter_url(): void
    {
        $link = SocialMediaLink::findIn('check this out https://twitter.com/someuser/status/1234567890.');

        $this->assertNotNull($link);
        $this->assertSame('https://twitter.com/someuser/status/1234567890', $link->url);
    }

    public function test_strips_wrapping_parentheses_from_twitter_url(): void
    {
        $link = SocialMediaLink::findIn('(https://twitter.com/someuser/status/1234567890)');

        $this->assertNotNull($link);
        $this->assertSame('https://twitter.com/someuser/status/1234567890', $link->url);
    }
}
