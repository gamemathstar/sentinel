<?php

namespace App\Modules\Authoring\Models;

use App\Modules\Tenancy\Models\OrgNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The authored definition of an exam (docs/01 §4.4): sections, blueprint, scoring rule,
 * proctoring policy, schedule. This is the template; a candidate's concrete assembled
 * paper is a Variant/Sitting in the Delivery module.
 */
class Assessment extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const KINDS = [
        'practice', 'ca', 'midterm', 'final', 'postutme', 'recruitment',
        'certification', 'licensing', 'mock',
    ];

    public const STATUSES = ['draft', 'published', 'live', 'closed', 'archived'];

    protected $table = 'assessments';

    protected $fillable = [
        'institution_id', 'org_node_id', 'title', 'kind', 'status',
        'window_opens_at', 'window_closes_at', 'duration_seconds', 'is_adaptive',
        'blueprint_id', 'scoring_rule_id', 'proctoring_policy_id',
    ];

    protected $casts = [
        'window_opens_at' => 'datetime',
        'window_closes_at' => 'datetime',
        'duration_seconds' => 'integer',
        'is_adaptive' => 'boolean',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(AssessmentSection::class)->orderBy('position');
    }

    public function scoringRule(): BelongsTo
    {
        return $this->belongsTo(ScoringRule::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function orgNode(): BelongsTo
    {
        return $this->belongsTo(OrgNode::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
