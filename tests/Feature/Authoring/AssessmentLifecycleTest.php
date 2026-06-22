<?php

namespace Tests\Feature\Authoring;

use App\Modules\Authoring\Events\AssessmentPublished;
use App\Modules\Authoring\Exceptions\PublishValidationFailed;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\BlueprintService;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\QuestionBank\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/** Assessment lifecycle: build from a blueprint, validate, publish, and pin reproducibly. */
class AssessmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_is_blocked_until_the_assessment_is_deliverable(): void
    {
        $this->makeTenant();
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'Empty', 'kind' => 'final']);

        try {
            $svc->publish($assessment);
            $this->fail('Expected PublishValidationFailed.');
        } catch (PublishValidationFailed $e) {
            // No scoring rule and no sections.
            $this->assertContains('a scoring rule must be set.', $e->errors);
            $this->assertContains('at least one section is required.', $e->errors);
        }

        $this->assertSame('draft', $assessment->fresh()->status);
    }

    public function test_full_build_and_publish_emits_event(): void
    {
        Event::fake([AssessmentPublished::class]);
        $this->makeTenant();
        foreach (range(1, 6) as $i) {
            $this->makeApprovedItem(0.8);
            $this->makeApprovedItem(0.2);
        }

        $rule = app(ScoringRuleService::class)->create('Standard', ['correct' => 1, 'wrong' => 0]);
        $blueprint = app(BlueprintService::class)->create('BP', [
            'total' => 4, 'difficulty' => ['easy' => 0.5, 'medium' => 0.0, 'hard' => 0.5],
        ]);

        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'Midterm', 'kind' => 'midterm', 'scoring_rule_id' => $rule->id]);
        $section = $svc->addSection($assessment, 'Section A');
        $assembled = $svc->assembleSectionFromBlueprint($section, $blueprint);

        $this->assertCount(4, $assembled);

        $svc->publish($assessment->fresh());
        $this->assertSame('published', $assessment->fresh()->status);
        Event::assertDispatched(AssessmentPublished::class, fn ($e) => $e->assessmentId === $assessment->id);
    }

    public function test_pinned_versions_survive_later_item_edits(): void
    {
        $this->makeTenant();
        $item = $this->makeApprovedItem(0.8);
        $pinnedVersionId = $item->current_version_id;

        $rule = app(ScoringRuleService::class)->create('R', ['correct' => 1]);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'A', 'kind' => 'ca', 'scoring_rule_id' => $rule->id]);
        $section = $svc->addSection($assessment, 'S');
        $svc->pinItemVersions($section, [$pinnedVersionId]);

        // Editing the item afterwards creates a NEW version and moves the pointer...
        $newVersion = app(ItemService::class)->addVersion($item, [
            'content' => ['stem' => 'edited', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['b']],
        ]);
        $this->assertNotSame($pinnedVersionId, $newVersion->id);

        // ...but the section still references the originally pinned version.
        $stillPinned = $section->itemVersions()->pluck('item_versions.id')->all();
        $this->assertSame([$pinnedVersionId], $stillPinned);
    }

    public function test_published_assessment_is_no_longer_editable(): void
    {
        $this->makeTenant();
        $this->makeApprovedItem(0.8);
        $rule = app(ScoringRuleService::class)->create('R', ['correct' => 1]);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'A', 'kind' => 'ca', 'scoring_rule_id' => $rule->id]);
        $section = $svc->addSection($assessment, 'S');
        $svc->pinItemVersions($section, [$this->makeApprovedItem(0.8)->current_version_id]);
        $svc->publish($assessment);

        $this->expectException(RuntimeException::class);
        $svc->addSection($assessment->fresh(), 'Too late');
    }
}
