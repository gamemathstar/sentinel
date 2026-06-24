<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\NotificationTransport;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Sends notifications idempotently (docs/03 §7 invariant: delivery is idempotent per
 * (recipient, event) via the unique dedupe_key). Composes the message, persists the
 * record, then delivers through the channel transport — a re-send with the same
 * dedupe_key returns the existing record without delivering twice.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationComposer $composer,
        private readonly NotificationTransport $transport,
    ) {}

    public function send(string $recipientId, string $channel, string $eventKey, array $context = [], ?string $dedupeKey = null): Notification
    {
        if (! in_array($channel, Notification::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown channel: {$channel}");
        }

        $dedupeKey ??= $eventKey.':'.$recipientId.':'.($context['ref'] ?? 'one-off');
        $rendered = $this->composer->compose($eventKey, $context);

        $notification = Notification::firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'recipient_id' => $recipientId,
                'channel' => $channel,
                'event_key' => $eventKey,
                'status' => 'queued',
                'payload' => array_merge($context, $rendered),
            ]
        );

        // Idempotent: already delivered for this (recipient, event) — do not resend.
        if (! $notification->wasRecentlyCreated && $notification->status === 'sent') {
            return $notification;
        }

        try {
            $this->transport->deliver($notification);
            $notification->forceFill(['status' => 'sent', 'sent_at' => Carbon::now()])->save();
        } catch (Throwable $e) {
            $notification->forceFill(['status' => 'failed'])->save();
        }

        return $notification;
    }
}
