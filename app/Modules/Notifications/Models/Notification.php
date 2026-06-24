<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification dispatched to a recipient over a channel (docs/01 §4.8, docs/03 §7).
 * Delivery is idempotent per (recipient, event) via the unique `dedupe_key`.
 */
class Notification extends Model
{
    use BelongsToTenant;

    public const CHANNELS = ['email', 'sms', 'push', 'whatsapp'];

    public const STATUSES = ['queued', 'sent', 'failed'];

    protected $table = 'notifications';

    protected $fillable = [
        'institution_id', 'recipient_id', 'channel', 'event_key', 'status', 'dedupe_key', 'payload', 'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
