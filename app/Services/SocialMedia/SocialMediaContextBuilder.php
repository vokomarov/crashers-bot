<?php

namespace App\Services\SocialMedia;

class SocialMediaContextBuilder
{
    public function buildFailureMessage(SocialMediaDownloadException $exception): string
    {
        return "Не вдалося завантажити медіа за посиланням, причина: {$exception->getMessage()}. Відповідай у своєму стилі.";
    }
}
