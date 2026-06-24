<?php

namespace App\Modules\Proctoring\Models;

use App\Modules\Delivery\Models\Sitting;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mirrors a sitting for monitoring (docs/01 §4.7, docs/05). Owns flags and evidence
 * clips and is summarized by a single explainable risk assessment.
 */
class ProctoringSession extends Model
{
    use BelongsToTenant;

    public const MODES = ['live', 'record_review', 'ai_only', 'none'];

    protected $table = 'proctoring_sessions';

    protected $fillable = ['sitting_id', 'institution_id', 'mode', 'lockdown_active', 'identity_verification'];

    protected $casts = [
        'lockdown_active' => 'boolean',
        'identity_verification' => 'array',
    ];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }

    public function flags(): HasMany
    {
        return $this->hasMany(ProctoringFlag::class);
    }

    public function evidenceClips(): HasMany
    {
        return $this->hasMany(EvidenceClip::class);
    }

    public function riskAssessment(): HasOne
    {
        return $this->hasOne(RiskAssessment::class);
    }
}
