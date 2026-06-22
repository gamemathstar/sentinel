<?php

namespace Tests\Feature\Analytics;

use App\Modules\Analytics\Services\PsychometricsCalculator;
use Tests\TestCase;

/**
 * The pure CTT math, checked against hand-computed values for a 3-item / 4-candidate set:
 *   c1: 1 1 1 (total 3)   c2: 1 1 0 (2)   c3: 1 0 0 (1)   c4: 0 0 0 (0)
 *   p = [.75, .50, .25]   totals = [3,2,1,0]   var(totals) = 1.25
 */
class PsychometricsCalculatorTest extends TestCase
{
    private PsychometricsCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PsychometricsCalculator;
    }

    public function test_facility_index(): void
    {
        $this->assertSame(0.75, $this->calc->facilityIndex([1, 1, 1, 0]));
        $this->assertSame(0.25, $this->calc->facilityIndex([1, 0, 0, 0]));
    }

    public function test_variance(): void
    {
        $this->assertEqualsWithDelta(1.25, $this->calc->variance([3, 2, 1, 0]), 1e-9);
    }

    public function test_point_biserial_discrimination(): void
    {
        // item1 = [1,1,1,0] vs totals [3,2,1,0]  ->  ~0.7746
        $this->assertEqualsWithDelta(0.7746, $this->calc->pointBiserial([1, 1, 1, 0], [3, 2, 1, 0]), 1e-4);
        // A constant item discriminates nothing.
        $this->assertSame(0.0, $this->calc->pointBiserial([1, 1, 1, 1], [3, 2, 1, 0]));
    }

    public function test_kr20_and_cronbach_match_for_dichotomous_items(): void
    {
        $p = [0.75, 0.5, 0.25];
        $itemVar = array_map(fn ($x) => $x * (1 - $x), $p);

        $kr20 = $this->calc->kr20($p, 1.25, 3);
        $alpha = $this->calc->cronbachAlpha($itemVar, 1.25, 3);

        $this->assertEqualsWithDelta(0.75, $kr20, 1e-9);
        $this->assertEqualsWithDelta($kr20, $alpha, 1e-9);
    }

    public function test_sem(): void
    {
        // sd = sqrt(1.25) ≈ 1.1180 ; sem = sd * sqrt(1 - 0.75) = sd * 0.5 ≈ 0.5590
        $this->assertEqualsWithDelta(0.5590, $this->calc->sem(sqrt(1.25), 0.75), 1e-4);
    }

    public function test_degenerate_inputs_are_safe(): void
    {
        $this->assertSame(0.0, $this->calc->kr20([0.5], 0.0, 1));        // k<2, zero variance
        $this->assertSame(0.0, $this->calc->variance([]));               // empty
        $this->assertSame(0.0, $this->calc->pointBiserial([], []));      // empty
    }

    public function test_distractor_analysis(): void
    {
        $out = $this->calc->distractorAnalysis(['a' => 3, 'b' => 1], ['a'], 4);
        $this->assertTrue($out['a']['correct']);
        $this->assertSame(0.75, $out['a']['proportion']);
        $this->assertFalse($out['b']['correct']);
        $this->assertSame(0.25, $out['b']['proportion']);
    }
}
