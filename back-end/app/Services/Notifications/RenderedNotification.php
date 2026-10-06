<?php

namespace App\Services\Notifications;

readonly class RenderedNotification
{
    public function __construct(public ?string $subject, public string $content, public ?string $templateName = null, public string $languageCode = 'ar', public array $parameters = []) {}
}
