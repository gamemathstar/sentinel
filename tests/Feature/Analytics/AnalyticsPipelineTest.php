<?php

namespace Tests\Feature\Analytics;

use App\Modules\Analytics\Models\AssessmentReliability;
use App\Modules\Analytics\Models\ItemStatistics;
use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\QuestionBank\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole pipeline: 4 candidates sit a 3-item exam in a known pattern, then analytics
 * is compiled. The computed statistics must match the hand-computed values, and the
 * measured difficulty must be written back to the bank (the authoring feedback loop).
 */
class AnalyticsPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiles_item_stats_reliability_and_feeds_difficulty_back(): void
    {
        $inst = $this->makeTenant();
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(3);
        [$iv0, $iv1, $iv2] = array_map(fn ($i) => $i->current_version_id, $items);

        // Response pattern (rows = candidates), giving p = [.75, .50, .25]:
        $patterns = [
            [$iv0 => true, $iv1 => true, $iv2 => true],   // total 3
            [$iv0 => true, $iv1 => true, $iv2 => false],  // total 2
            [$iv0 => true, $iv1 => false, $iv2 => false], // total 1
            [$iv0 => false, $iv1 => false, $iv2 => false], // total 0
        ];
        foreach ($patterns as $pattern) {
            $this->runSitting($assessment, $this->makeUser($inst), $pattern);
        }

        app(AnalyticsService::class)->compileAssessment($assessment);

        // Item statistics.
        $stats0 = ItemStatistics::where('item_id', $items[0]->id)->firstOrFail();
        $this->assertSame(4, $stats0->sample_n);
        $this->assertEqualsWithDelta(0.75, $stats0->facility_index, 1e-4);
        $this->assertEqualsWithDelta(0.7746, $stats0->discrimination_index, 1e-3);

        $this->assertEqualsWithDelta(0.25, ItemStatistics::where('item_id', $items[2]->id)->value('facility_index'), 1e-4);

        // Assessment reliability.
        $rel = AssessmentReliability::where('assessment_id', $assessment->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.75, $rel->kr20, 1e-3);
        $this->assertEqualsWithDelta(0.75, $rel->cronbach_alpha, 1e-3);
        $this->assertEqualsWithDelta(0.559, $rel->sem, 1e-3);

        // Feedback loop: measured facility written back to the bank.
        $this->assertEqualsWithDelta(0.75, (float) Item::find($items[0]->id)->difficulty, 1e-4);
        $this->assertEqualsWithDelta(0.25, (float) Item::find($items[2]->id)->difficulty, 1e-4);
    }

    public function test_distractor_analysis_marks_the_correct_option(): void
    {
        $inst = $this->makeTenant();
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(1);
        $iv = $items[0]->current_version_id;

        // 3 choose correct ('a'), 1 chooses the distractor.
        $this->runSitting($assessment, $this->makeUser($inst), [$iv => true]);
        $this->runSitting($assessment, $this->makeUser($inst), [$iv => true]);
        $this->runSitting($assessment, $this->makeUser($inst), [$iv => true]);
        $this->runSitting($assessment, $this->makeUser($inst), [$iv => false]);

        app(AnalyticsService::class)->compileAssessment($assessment);

        $analysis = ItemStatistics::where('item_id', $items[0]->id)->value('distractor_analysis');
        $this->assertTrue($analysis['a']['correct']);
        $this->assertSame(3, $analysis['a']['count']);
        $this->assertFalse($analysis['b']['correct']);
        $this->assertSame(1, $analysis['b']['count']);
    }

    public function test_compile_requires_graded_sittings(): void
    {
        $this->makeTenant();
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $this->expectException(\RuntimeException::class);
        app(AnalyticsService::class)->compileAssessment($assessment);
    }
}
