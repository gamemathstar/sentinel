<?php

namespace App\Modules\Certification\Models;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued, verifiable credential (docs/01 §4.8, docs/03 §7). The `payload` is an
 * immutable snapshot of the result so a third party can verify authenticity via the
 * public portal without trusting the issuer's live database; `content_hash` makes
 * tampering detectable; `anchor_txid` optionally commits the hash to an external ledger.
 */
class Certificate extends Model
{
    use BelongsToTenant;

    protected $table = 'certificates';

    protected $fillable = [
        'institution_id', 'candidate_id', 'assessment_id', 'serial', 'verification_token',
        'payload', 'content_hash', 'anchor_txid', 's3_key', 'issued_at', 'revoked_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAnchored(): bool
    {
        return $this->anchor_txid !== null;
    }
}
