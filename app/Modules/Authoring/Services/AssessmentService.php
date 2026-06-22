<?php

namespace App\Modules\Authoring\Services;

use App\Modules\Authoring\Events\AssessmentPublished;
use App\Modules\Authoring\Exceptions\PublishValidationFailed;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Models\AssessmentSection;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\QuestionBank\Models\ItemVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application service for the Assessment aggregate (docs/01 §4.4). Owns the lifecycle
 * (draft -> published) and the composition of sections, including assembling a section
 * from a blueprint. Sections pin item *versions*, so a published paper is reproducible
 * forever even after the bank changes (docs/03 §3).
 */
class AssessmentService
{
    public function __construct(private readonly PaperAssembler $assembler) {}

    public function create(array $attributes): Assessment
    {
        if (! in_array($attributes['kind'] ?? '', Assessment::KINDS, true)) {
            throw new InvalidArgumentException('Unknown assessment kind.');
        }

        return Assessment::create([
            'title' => $attributes['title'],
            'kind' => $attributes['kind'],
            'status' => 'draft',
            'org_node_id' => $attributes['org_node_id'] ?? null,
            'duration_seconds' => $attributes['duration_seconds'] ?? null,
            'window_opens_at' => $attributes['window_opens_at'] ?? null,
            'window_closes_at' => $attributes['window_closes_at'] ?? null,
            'is_adaptive' => $attributes['is_adaptive'] ?? false,
            'blueprint_id' => $attributes['blueprint_id'] ?? null,
            'scoring_rule_id' => $attributes['scoring_rule_id'] ?? null,
            'proctoring_policy_id' => $attributes['proctoring_policy_id'] ?? null,
        ]);
    }

    public function addSection(Assessment $assessment, string $title, ?array $selection = null): AssessmentSection
    {
        $this->assertEditable($assessment);
        $position = (int) $assessment->sections()->max('position') + 1;

        return AssessmentSection::create([
            'assessment_id' => $assessment->id,
            'title' => $title,
            'position' => $position,
            'selection' => $selection ?? [],
        ]);
    }

    /** Pin an explicit, ordered set of item versions into a section. */
    public function pinItemVersions(AssessmentSection $section, array $itemVersionIds): void
    {
        $this->assertEditable($section->assessment);
        $this->assertVersionsExist($itemVersionIds);

        $rows = [];
        $position = (int) DB::table('section_item')->where('section_id', $section->id)->max('position');
        foreach ($itemVersionIds as $vid) {
            $rows[] = ['section_id' => $section->id, 'item_version_id' => $vid, 'position' => ++$position];
        }
        DB::table('section_item')->insertOrIgnore($rows);
    }

    /**
     * Assemble a section's items automatically from a blueprint and pin the result.
     * The selection recipe is recorded on the section for traceability.
     */
    public function assembleSectionFromBlueprint(AssessmentSection $section, Blueprint $blueprint): array
    {
        $this->assertEditable($section->assessment);

        $versionIds = $this->assembler->assemble($blueprint);
        $this->pinItemVersions($section, $versionIds);

        $section->selection = ['source' => 'blueprint', 'blueprint_id' => $blueprint->id, 'count' => count($versionIds)];
        $section->save();

        return $versionIds;
    }

    /**
     * Publish an assessment after validating it is deliverable.
     *
     * @throws PublishValidationFailed
     */
    public function publish(Assessment $assessment): Assessment
    {
        $errors = $this->publishErrors($assessment);
        if ($errors !== []) {
            throw new PublishValidationFailed($errors);
        }

        $assessment->status = 'published';
        $assessment->save();

        AssessmentPublished::dispatch($assessment->id);

        return $assessment;
    }

    /** @return string[] reasons the assessment is not publishable (empty = ready) */
    public function publishErrors(Assessment $assessment): array
    {
        $errors = [];

        if ($assessment->status !== 'draft') {
            $errors[] = "only a draft can be published (status is {$assessment->status}).";
        }
        if (! $assessment->scoring_rule_id) {
            $errors[] = 'a scoring rule must be set.';
        }
        if ($assessment->sections()->count() === 0) {
            $errors[] = 'at least one section is required.';
        }
        foreach ($assessment->sections as $section) {
            if (DB::table('section_item')->where('section_id', $section->id)->count() === 0) {
                $errors[] = "section '{$section->title}' has no items.";
            }
        }
        if ($assessment->window_opens_at && $assessment->window_closes_at
            && $assessment->window_opens_at->gte($assessment->window_closes_at)) {
            $errors[] = 'the exam window opens at or after it closes.';
        }

        return $errors;
    }

    private function assertEditable(Assessment $assessment): void
    {
        if (! $assessment->isEditable()) {
            throw new RuntimeException("Assessment is {$assessment->status} and can no longer be edited.");
        }
    }

    private function assertVersionsExist(array $itemVersionIds): void
    {
        // whereHas('item') applies the Item tenant scope, so cross-tenant version ids are rejected.
        $found = ItemVersion::whereHas('item')->whereIn('id', $itemVersionIds)->count();
        if ($found !== count(array_unique($itemVersionIds))) {
            throw new InvalidArgumentException('One or more item versions do not exist in this tenant.');
        }
    }
}
