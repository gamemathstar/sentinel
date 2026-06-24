<?php

namespace App\Modules\Proctoring\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encrypted evidence media captured around a flagged window (docs/01 §4.7, docs/05 §9).
 * Retention is policy-bound; access to evidence is itself an audited action.
 */
class EvidenceClip extends Model
{
    use HasUuidv7;

    public const KINDS = ['video', 'audio', 'screenshot', 'screen'];

    protected $table = 'evidence_clips';

    protected $fillable = ['proctoring_session_id', 's3_key', 'kind', 'from_ts', 'to_ts'];

    protected $casts = [
        'from_ts' => 'datetime',
        'to_ts' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProctoringSession::class, 'proctoring_session_id');
    }
}
