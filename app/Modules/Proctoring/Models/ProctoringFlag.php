<?php

namespace App\Modules\Proctoring\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proctoring observation of potential misconduct (docs/01 §4.7, docs/05 §4): a typed,
 * confidence-scored, timestamped, evidence-linked signal. Part of the session aggregate.
 */
class ProctoringFlag extends Model
{
    use HasUuidv7;

    public const SOURCES = ['client', 'edge', 'server_inference'];

    protected $table = 'proctoring_flags';

    protected $fillable = [
        'proctoring_session_id', 'type', 'confidence', 'occurred_at', 'evidence_clip_id', 'source',
    ];

    protected $casts = [
        'confidence' => 'float',
        'occurred_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProctoringSession::class, 'proctoring_session_id');
    }
}
