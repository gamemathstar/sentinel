<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer-token session (docs/01 §4.1, schema `sessions`). The presented token is
 * "{session_id}.{secret}"; only a hash of the secret is stored, so a DB read cannot
 * reconstruct a usable token. Revocation and expiry are explicit columns.
 */
class AuthSession extends Model
{
    use HasUuidv7;

    protected $table = 'sessions';

    protected $fillable = [
        'user_id', 'ip', 'user_agent', 'refresh_token_hash', 'last_active_at', 'revoked_at', 'expires_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
