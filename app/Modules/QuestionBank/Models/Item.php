<?php

namespace App\Modules\QuestionBank\Models;

use App\Modules\Tenancy\Models\OrgNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The reusable question aggregate root (docs/01 §4.3). Owns its versions. Deliberately
 * has NO correct-answer field — scoring truth lives in the vault (AnswerKeyVault).
 */
class Item extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Question types the platform supports (docs spec). New types are data, not schema. */
    public const TYPES = [
        'single', 'multiple', 'true_false', 'yes_no', 'assertion_reason', 'matching',
        'ordering', 'drag_drop', 'hotspot', 'image_selection', 'case_study', 'clinical',
        'essay', 'short_answer', 'fill_blank', 'numerical', 'formula', 'code', 'sql',
        'simulation', 'virtual_lab',
    ];

    /** Types scored automatically against the vault answer key (objective). */
    public const OBJECTIVE_TYPES = [
        'single', 'multiple', 'true_false', 'yes_no', 'fill_blank', 'numerical', 'matching', 'ordering',
    ];

    protected $table = 'items';

    protected $fillable = [
        'institution_id', 'type', 'current_version_id', 'status',
        'difficulty', 'discrimination', 'bloom_level', 'expected_seconds', 'default_weight',
    ];

    protected $casts = [
        'difficulty' => 'float',
        'discrimination' => 'float',
        'bloom_level' => 'integer',
        'expected_seconds' => 'integer',
        'default_weight' => 'float',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(ItemVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ItemVersion::class, 'current_version_id');
    }

    public function orgNodes(): BelongsToMany
    {
        return $this->belongsToMany(OrgNode::class, 'item_org_node');
    }

    public function isObjective(): bool
    {
        return in_array($this->type, self::OBJECTIVE_TYPES, true);
    }
}
