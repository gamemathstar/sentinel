<?php

namespace Tests\Feature\Authoring;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end authoring flow over HTTP, with permission enforcement. */
class AuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    /** @return array{0:Institution,1:User} */
    private function tenantAndOfficer(): array
    {
        $inst = Institution::create(['name' => 'Auth U', 'slug' => 'auth-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');

        return [$inst, $officer];
    }

    public function test_officer_can_build_and_publish_an_assessment(): void
    {
        [$inst, $officer] = $this->tenantAndOfficer();
        foreach (range(1, 4) as $i) {
            $this->makeApprovedItem(0.8);
            $this->makeApprovedItem(0.2);
        }
        $headers = $this->authHeaders($officer);

        $rule = $this->postJson('/api/authoring/scoring-rules', [
            'name' => 'Std', 'policy' => ['correct' => 1, 'wrong' => 0],
        ], $headers)->assertCreated()->json();

        $blueprint = $this->postJson('/api/authoring/blueprints', [
            'name' => 'BP', 'constraints' => ['total' => 4, 'difficulty' => ['easy' => 0.5, 'medium' => 0.0, 'hard' => 0.5]],
        ], $headers)->assertCreated()->json();

        $assessment = $this->postJson('/api/authoring/assessments', [
            'title' => 'Final', 'kind' => 'final', 'scoring_rule_id' => $rule['id'],
        ], $headers)->assertCreated()->json();

        $section = $this->postJson("/api/authoring/assessments/{$assessment['id']}/sections", [
            'title' => 'A',
        ], $headers)->assertCreated()->json();

        $this->postJson("/api/authoring/assessments/{$assessment['id']}/sections/{$section['id']}/assemble", [
            'blueprint_id' => $blueprint['id'],
        ], $headers)->assertCreated()->assertJsonPath('assembled', 4);

        $this->postJson("/api/authoring/assessments/{$assessment['id']}/publish", [], $headers)
            ->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_assemble_returns_422_on_shortfall(): void
    {
        [$inst, $officer] = $this->tenantAndOfficer();
        $this->makeApprovedItem(0.8); // only one easy item
        $headers = $this->authHeaders($officer);

        $blueprint = $this->postJson('/api/authoring/blueprints', [
            'name' => 'BP', 'constraints' => ['total' => 5, 'difficulty' => ['easy' => 1.0, 'medium' => 0.0, 'hard' => 0.0]],
        ], $headers)->json();

        $assessment = $this->postJson('/api/authoring/assessments', [
            'title' => 'X', 'kind' => 'final',
        ], $headers)->json();

        $section = $this->postJson("/api/authoring/assessments/{$assessment['id']}/sections", [
            'title' => 'A',
        ], $headers)->json();

        $this->postJson("/api/authoring/assessments/{$assessment['id']}/sections/{$section['id']}/assemble", [
            'blueprint_id' => $blueprint['id'],
        ], $headers)->assertStatus(422)->assertJsonPath('shortfall.easy.needed', 5);
    }

    public function test_student_cannot_create_assessment(): void
    {
        $inst = Institution::create(['name' => 'S U', 'slug' => 's-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson('/api/authoring/assessments', [
            'title' => 'Nope', 'kind' => 'final',
        ], $this->authHeaders($student))->assertStatus(403);
    }

    public function test_publish_returns_422_with_reasons_when_incomplete(): void
    {
        [, $officer] = $this->tenantAndOfficer();
        $headers = $this->authHeaders($officer);

        $assessment = $this->postJson('/api/authoring/assessments', [
            'title' => 'Incomplete', 'kind' => 'final',
        ], $headers)->json();

        $this->postJson("/api/authoring/assessments/{$assessment['id']}/publish", [], $headers)
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }
}
