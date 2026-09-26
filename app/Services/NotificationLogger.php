<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;

class NotificationLogger
{
    public function log(
        NotificationChannel $channel,
        string $type,
        string $recipient,
        NotificationStatus $status = NotificationStatus::Queued,
        array $context = [],
    ): NotificationLog {
        return NotificationLog::create([
            'channel' => $channel,
            'type' => $type,
            'recipient' => $recipient,
            'status' => $status,
            'company_id' => $context['company_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'application_id' => $context['application_id'] ?? null,
            'payload' => $context['payload'] ?? null,
            'error' => $context['error'] ?? null,
            'sent_at' => $status === NotificationStatus::Sent ? now() : null,
        ]);
    }
}
