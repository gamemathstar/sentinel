<?php

namespace Tests\Feature\Reporting;

use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP: generate + download reports with permission enforcement. */
class ReportingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionRbac();
    }

    private function scenario(): array
    {
        $inst = Institution::create(['name' => 'Rep U', 'slug' => 'rep-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);
        $this->runSitting($assessment, $this->makeUser($inst), [$ivs[0] => true, $ivs[1] => true]);

        return [$inst, $assessment];
    }

    public function test_officer_generates_and_downloads_a_report(): void
    {
        [$inst, $assessment] = $this->scenario();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $headers = $this->authHeaders($officer);

        $report = $this->postJson('/api/reporting/reports', [
            'type' => 'results', 'format' => 'csv', 'params' => ['assessment_id' => $assessment->id],
        ], $headers)->assertCreated()->assertJsonPath('status', 'completed')->json();

        $this->get("/api/reporting/reports/{$report['id']}/download", $headers)
            ->assertOk()
            ->assertDownload();
    }

    public function test_invalid_format_is_422(): void
    {
        [$inst, $assessment] = $this->scenario();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');

        $this->postJson('/api/reporting/reports', [
            'type' => 'results', 'format' => 'docx', 'params' => ['assessment_id' => $assessment->id],
        ], $this->authHeaders($officer))->assertStatus(422);
    }

    public function test_student_cannot_generate_reports(): void
    {
        [$inst, $assessment] = $this->scenario();
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson('/api/reporting/reports', [
            'type' => 'results', 'format' => 'csv', 'params' => ['assessment_id' => $assessment->id],
        ], $this->authHeaders($student))->assertStatus(403);
    }
}
