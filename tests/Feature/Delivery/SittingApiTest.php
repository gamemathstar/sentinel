<?php

namespace Tests\Feature\Delivery;

use App\Modules\Delivery\Models\VariantManifest;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end candidate exam flow over HTTP, with ownership enforcement. */
class SittingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_full_candidate_flow_assign_take_submit_score(): void
    {
        $inst = Institution::create(['name' => 'Exam U', 'slug' => 'exam-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $candidate = $this->makeUser($inst);
        $this->grantRole($candidate, 'student');

        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);

        // Officer assigns the candidate.
        $assign = $this->postJson("/api/delivery/assessments/{$assessment->id}/sittings", [
            'candidate_id' => $candidate->id,
        ], $this->authHeaders($officer));
        $assign->assertCreated();
        $sittingId = $assign->json('id');

        $candHeaders = $this->authHeaders($candidate);

        // Start.
        $this->postJson("/api/delivery/sittings/{$sittingId}/start", [], $candHeaders)
            ->assertOk()->assertJsonPath('status', 'in_progress');

        // Fetch the paper — no answer keys leak.
        $show = $this->getJson("/api/delivery/sittings/{$sittingId}", $candHeaders)->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('correct', json_encode($show->json('questions')));
        $this->assertCount(2, $show->json('questions'));

        // Answer both correctly (map canonical 'a' via the stored manifest).
        $manifest = VariantManifest::where('sitting_id', $sittingId)->first();
        foreach ($items as $item) {
            $iv = $item->current_version_id;
            $idx = (int) array_search('a', $manifest->optionOrderFor($iv), true);
            $this->postJson("/api/delivery/sittings/{$sittingId}/responses", [
                'item_version_id' => $iv,
                'answer' => ['selected' => [$idx]],
            ], $candHeaders)->assertCreated();
        }

        // Submit -> scored.
        $this->postJson("/api/delivery/sittings/{$sittingId}/submit", [], $candHeaders)
            ->assertOk()
            ->assertJsonPath('status', 'graded')
            ->assertJsonPath('score.raw_score', 2)
            ->assertJsonPath('score.status', 'final');

        // Candidate can read their score.
        $this->getJson("/api/delivery/sittings/{$sittingId}/score", $candHeaders)
            ->assertOk()->assertJsonPath('raw_score', 2);
    }

    public function test_candidate_cannot_access_another_candidates_sitting(): void
    {
        $inst = Institution::create(['name' => 'X U', 'slug' => 'x-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $owner = $this->makeUser($inst);
        $this->grantRole($owner, 'student');
        $intruder = $this->makeUser($inst);
        $this->grantRole($intruder, 'student');

        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);
        $sitting = app(SittingService::class)->assign($assessment, $owner);

        $this->postJson("/api/delivery/sittings/{$sitting->id}/start", [], $this->authHeaders($intruder))
            ->assertStatus(403);
    }

    public function test_student_cannot_assign_sittings(): void
    {
        $inst = Institution::create(['name' => 'Y U', 'slug' => 'y-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $this->postJson("/api/delivery/assessments/{$assessment->id}/sittings", [
            'candidate_id' => $student->id,
        ], $this->authHeaders($student))->assertStatus(403);
    }
}
