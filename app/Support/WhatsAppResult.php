<?php

namespace App\Support;

use App\Enums\NotificationStatus;

readonly class WhatsAppResult
{
    public function __construct(
        public NotificationStatus $status,
        public ?string $url = null,
        public ?string $error = null,
    ) {}

    public static function link(string $url): self
    {
        // The link driver only prepares a click-to-chat URL; a human presses send.
        return new self(NotificationStatus::Queued, $url);
    }

    public static function failed(string $error): self
    {
        return new self(NotificationStatus::Failed, null, $error);
    }
}
