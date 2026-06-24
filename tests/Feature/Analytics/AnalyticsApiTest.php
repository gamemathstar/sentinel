<?php

namespace Tests\Feature\Analytics;

use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP surface for analytics: compile + read, with permission enforcement. */
class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_officer_compiles_and_reads_analytics(): void
    {
        $inst = Institution::create(['name' => 'An U', 'slug' => 'an-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');

        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);
        $this->runSitting($assessment, $this->makeUser($inst), [$ivs[0] => true, $ivs[1] => true]);
        $this->runSitting($assessment, $this->makeUser($inst), [$ivs[0] => true, $ivs[1] => false]);

        $headers = $this->authHeaders($officer);

        $this->postJson("/api/analytics/assessments/{$assessment->id}/compile", [], $headers)
            ->assertCreated()
            ->assertJsonStructure(['kr20', 'cronbach_alpha', 'sem']);

        $this->getJson("/api/analytics/assessments/{$assessment->id}/reliability", $headers)
            ->assertOk()->assertJsonStructure(['kr20']);

        $this->getJson("/api/analytics/items/{$items[0]->id}/statistics", $headers)
            ->assertOk()->assertJsonStructure(['facility_index', 'discrimination_index', 'distractor_analysis']);

        // Assessment-wide item-analysis table: one row per pinned item, with computed metrics.
        $this->getJson("/api/analytics/assessments/{$assessment->id}/items", $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['item_id', 'type', 'stem', 'facility_index', 'discrimination_index', 'sample_n']]]);
    }

    public function test_student_cannot_compile_analytics(): void
    {
        $inst = Institution::create(['name' => 'St U', 'slug' => 'st-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $this->postJson("/api/analytics/assessments/{$assessment->id}/compile", [], $this->authHeaders($student))
            ->assertStatus(403);
    }
}
