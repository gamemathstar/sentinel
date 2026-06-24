<?php

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Models\Notification;

/**
 * Anti-corruption interface to the actual delivery providers (SES/Twilio/FCM/WhatsApp
 * Cloud API). The domain calls this; swap the binding per channel for real providers
 * without touching NotificationService. Throwing signals a delivery failure.
 */
interface NotificationTransport
{
    public function deliver(Notification $notification): void;
}
