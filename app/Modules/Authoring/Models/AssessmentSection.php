<?php

namespace App\Modules\Authoring\Models;

use App\Modules\QuestionBank\Models\ItemVersion;
use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A section of an assessment (docs/03 §3). Part of the Assessment aggregate; isolated
 * transitively via its assessment. References PINNED item versions for reproducibility.
 */
class AssessmentSection extends Model
{
    use HasUuidv7;

    protected $table = 'assessment_sections';

    protected $fillable = ['assessment_id', 'title', 'position', 'selection', 'scoring_rule_id'];

    protected $casts = ['selection' => 'array', 'position' => 'integer'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** The pinned item versions that make up this section, in order. */
    public function itemVersions(): BelongsToMany
    {
        return $this->belongsToMany(ItemVersion::class, 'section_item', 'section_id', 'item_version_id')
            ->withPivot('position')
            ->orderBy('section_item.position');
    }
}
