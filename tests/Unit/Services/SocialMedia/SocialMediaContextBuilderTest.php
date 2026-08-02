<?php

namespace Tests\Unit\Services\SocialMedia;

use App\Services\SocialMedia\SocialMediaContextBuilder;
use App\Services\SocialMedia\SocialMediaDownloadException;
use PHPUnit\Framework\TestCase;

class SocialMediaContextBuilderTest extends TestCase
{
    private SocialMediaContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SocialMediaContextBuilder();
    }

    public function test_builds_failure_message_with_exception_reason(): void
    {
        $exception = new SocialMediaDownloadException('приватний акаунт');

        $message = $this->builder->buildFailureMessage($exception);

        $this->assertSame(
            'Не вдалося завантажити медіа за посиланням, причина: приватний акаунт. Відповідай у своєму стилі.',
            $message
        );
    }
}
