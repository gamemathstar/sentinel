<?php

namespace App\Modules\Notifications\Transports;

use App\Modules\Notifications\Contracts\NotificationTransport;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * PLACEHOLDER transport (docs/04 §11): writes the notification to the log instead of
 * hitting a real provider, so the workflow is exercisable today. Production binds a
 * per-channel provider (email→SES, sms→Twilio, push→FCM, whatsapp→Cloud API).
 */
class LogTransport implements NotificationTransport
{
    public function deliver(Notification $notification): void
    {
        Log::info('notification.delivered', [
            'channel' => $notification->channel,
            'recipient_id' => $notification->recipient_id,
            'event' => $notification->event_key,
            'subject' => $notification->payload['subject'] ?? null,
        ]);
    }
}
