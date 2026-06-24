<?php

namespace Tests\Feature\Proctoring;

use App\Modules\Authoring\Models\ProctoringPolicy;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Proctoring\Services\ProctoringService;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP proctoring flow: a proctor flags + assesses, QA reviews; permission enforcement. */
class ProctoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    /** @return array{0:Institution,1:Sitting} */
    private function proctoredSitting(): array
    {
        $inst = Institution::create(['name' => 'Pr U', 'slug' => 'pr-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $policy = ProctoringPolicy::create([
            'institution_id' => $inst->id, 'name' => 'P', 'mode' => 'ai_only', 'lockdown_required' => true, 'signals' => [],
        ]);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, proctoringPolicyId: $policy->id);
        $sitting = app(SittingService::class)->assign($assessment, $this->makeUser($inst));

        return [$inst, $sitting];
    }

    public function test_proctor_opens_session_flags_and_assesses_then_qa_reviews(): void
    {
        [$inst, $sitting] = $this->proctoredSitting();
        $proctor = $this->makeUser($inst);
        $this->grantRole($proctor, 'proctor');
        $ph = $this->authHeaders($proctor);

        $session = $this->postJson("/api/proctoring/sittings/{$sitting->id}/session", [], $ph)
            ->assertCreated()->json();

        $this->postJson("/api/proctoring/sessions/{$session['id']}/flags", [
            'type' => 'phone_detected', 'confidence' => 0.95, 'source' => 'server_inference',
        ], $ph)->assertCreated();

        $risk = $this->postJson("/api/proctoring/sessions/{$session['id']}/assess", [], $ph)
            ->assertCreated()->json();
        $this->assertGreaterThan(0.6, $risk['cheating_probability']);

        // QA (exam_officer) sees it in the review queue and clears/upholds it.
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $oh = $this->authHeaders($officer);

        $this->getJson('/api/proctoring/review-queue', $oh)->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/proctoring/risk/{$risk['id']}/review", ['decision' => 'upheld'], $oh)
            ->assertOk()->assertJsonPath('status', 'upheld');
    }

    public function test_student_cannot_record_flags(): void
    {
        [$inst, $sitting] = $this->proctoredSitting();
        // Open a session as a proctor first.
        $session = app(ProctoringService::class)->openSession($sitting);

        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson("/api/proctoring/sessions/{$session->id}/flags", [
            'type' => 'tab_switch', 'confidence' => 0.5,
        ], $this->authHeaders($student))->assertStatus(403);
    }

    public function test_a_proctor_cannot_access_the_review_queue(): void
    {
        [$inst] = $this->proctoredSitting();
        $proctor = $this->makeUser($inst);
        $this->grantRole($proctor, 'proctor'); // monitor only, no review

        $this->getJson('/api/proctoring/review-queue', $this->authHeaders($proctor))->assertStatus(403);
    }
}
