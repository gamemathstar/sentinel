<?php

namespace Tests\Feature\Reporting;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Authoring\Models\ProctoringPolicy;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Proctoring\Services\ProctoringService;
use App\Modules\Reporting\Exceptions\ReportingError;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\ReportCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Report data builders + CSV/XLSX/PDF renderers, producing real artifacts. */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private string $assessmentId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildScenario();
    }

    /** A graded, analyzed, partly-flagged assessment to report on. */
    private function buildScenario(): void
    {
        $inst = $this->makeTenant();
        $policy = ProctoringPolicy::create([
            'institution_id' => $inst->id, 'name' => 'P', 'mode' => 'ai_only', 'lockdown_required' => true, 'signals' => [],
        ]);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(3, proctoringPolicyId: $policy->id);
        $this->assessmentId = $assessment->id;
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);

        $patterns = [
            [$ivs[0] => true, $ivs[1] => true, $ivs[2] => true],
            [$ivs[0] => true, $ivs[1] => true, $ivs[2] => false],
            [$ivs[0] => true, $ivs[1] => false, $ivs[2] => false],
        ];
        $first = null;
        foreach ($patterns as $p) {
            $s = $this->runSitting($assessment, $this->makeUser($inst), $p);
            $first ??= $s;
        }

        // Flag + assess one candidate so the risk report has content.
        $session = ProctoringSession::where('sitting_id', $first->id)->first();
        $proctoring = app(ProctoringService::class);
        $proctoring->recordFlag($session, 'phone_detected', 0.95, 'server_inference');
        $proctoring->assess($session);

        app(AnalyticsService::class)->compileAssessment($assessment->fresh());
    }

    public function test_results_report_as_csv_has_rows_and_an_artifact(): void
    {
        $report = app(ReportingService::class)->generate(
            ReportCatalog::TYPE_RESULTS, 'csv', ['assessment_id' => $this->assessmentId]
        );

        $this->assertSame('completed', $report->status);
        $this->assertSame(3, $report->rows);
        $this->assertTrue(Storage::disk('local')->exists($report->path));

        $csv = Storage::disk('local')->get($report->path);
        $this->assertStringContainsString('Candidate', $csv);   // header
        $this->assertStringContainsString('Raw Score', $csv);
    }

    public function test_item_quality_report_as_xlsx_is_a_real_workbook(): void
    {
        $report = app(ReportingService::class)->generate(
            ReportCatalog::TYPE_ITEM_QUALITY, 'xlsx', ['assessment_id' => $this->assessmentId]
        );

        $this->assertSame('completed', $report->status);
        $this->assertSame(3, $report->rows); // 3 items, all with statistics
        $bytes = Storage::disk('local')->get($report->path);
        $this->assertStringStartsWith('PK', $bytes); // xlsx is a zip container
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_assessment_summary_report_as_pdf_is_a_real_pdf(): void
    {
        $report = app(ReportingService::class)->generate(
            ReportCatalog::TYPE_ASSESSMENT_SUMMARY, 'pdf', ['assessment_id' => $this->assessmentId]
        );

        $this->assertSame('completed', $report->status);
        $bytes = Storage::disk('local')->get($report->path);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_risk_report_lists_the_flagged_candidate(): void
    {
        $report = app(ReportingService::class)->generate(
            ReportCatalog::TYPE_RISK, 'csv', ['assessment_id' => $this->assessmentId]
        );

        $this->assertSame(1, $report->rows);
        $csv = Storage::disk('local')->get($report->path);
        $this->assertStringContainsString('phone_detected', $csv); // top contributing signal
    }

    public function test_unknown_type_or_format_is_rejected(): void
    {
        $svc = app(ReportingService::class);
        $this->expectException(ReportingError::class);
        $svc->generate('telemetry', 'csv', ['assessment_id' => $this->assessmentId]);
    }

    public function test_report_is_stamped_with_the_current_tenant(): void
    {
        $report = app(ReportingService::class)->generate(
            ReportCatalog::TYPE_RESULTS, 'csv', ['assessment_id' => $this->assessmentId]
        );
        $this->assertSame(app(TenantContext::class)->institutionId(), $report->institution_id);
    }
}
