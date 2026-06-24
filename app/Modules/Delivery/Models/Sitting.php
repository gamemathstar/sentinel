<?php

namespace App\Modules\Delivery\Models;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One candidate's attempt at an assessment (docs/01 §4.5) — the hottest aggregate.
 * Owns its responses (append-only) and references a pre-assembled variant manifest.
 */
class Sitting extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['assigned', 'in_progress', 'submitted', 'graded', 'voided'];

    protected $table = 'sittings';

    protected $fillable = [
        'institution_id', 'assessment_id', 'candidate_id', 'status',
        'started_at', 'submitted_at', 'server_deadline_epoch', 'variant_token', 'sync_meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'server_deadline_epoch' => 'integer',
        'sync_meta' => 'array',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function manifest(): HasOne
    {
        return $this->hasOne(VariantManifest::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function score(): HasOne
    {
        return $this->hasOne(Score::class);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /** Server-authoritative deadline check — the client clock is never trusted (docs/04 §8). */
    public function isPastDeadline(?int $nowEpoch = null): bool
    {
        return $this->server_deadline_epoch !== null
            && ($nowEpoch ?? time()) > $this->server_deadline_epoch;
    }

    /** Seconds left against the server deadline; null for an untimed sitting. */
    public function remainingSeconds(?int $nowEpoch = null): ?int
    {
        if ($this->server_deadline_epoch === null) {
            return null;
        }

        return max(0, $this->server_deadline_epoch - ($nowEpoch ?? time()));
    }
}
