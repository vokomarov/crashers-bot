<?php

namespace App\Services\SocialMedia;

final readonly class SocialMediaResource
{
    public function __construct(
        public SocialMediaResourceType $type,
        public string $path,
        public string $thumbnailPath,
        public ?string $title,
        public ?string $description,
    ) {
    }
}
