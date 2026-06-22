<?php

namespace Tests\Feature\Authoring;

use App\Modules\Authoring\Models\ScoringPolicy;
use Tests\TestCase;

/** The pure scoring evaluator (reused by Delivery at submit time). No DB needed. */
class ScoringPolicyTest extends TestCase
{
    public function test_positive_and_negative_marking(): void
    {
        // +4 correct, -1 wrong, 0 blank.
        $policy = new ScoringPolicy(['correct' => 4, 'wrong' => -1, 'blank' => 0]);

        $result = $policy->evaluate([
            ['fraction' => 1.0],   // correct  -> +4
            ['fraction' => 1.0],   // correct  -> +4
            ['fraction' => 1.0],   // correct  -> +4
            ['fraction' => 0.0],   // wrong    -> -1
            ['fraction' => null],  // blank    ->  0
        ]);

        $this->assertSame(11.0, $result['raw']);
        $this->assertSame(20.0, $result['max']);
    }

    public function test_partial_credit_toggle(): void
    {
        $withPartial = new ScoringPolicy(['correct' => 2, 'wrong' => 0, 'partial' => true]);
        $this->assertSame(1.0, $withPartial->evaluate([['fraction' => 0.5]])['raw']);

        $withoutPartial = new ScoringPolicy(['correct' => 2, 'wrong' => 0, 'partial' => false]);
        $this->assertSame(0.0, $withoutPartial->evaluate([['fraction' => 0.5]])['raw']);
    }

    public function test_weighting_and_scaling(): void
    {
        $policy = new ScoringPolicy(['correct' => 1, 'wrong' => 0, 'scale' => 100]);

        $result = $policy->evaluate([
            ['fraction' => 1.0, 'weight' => 3],
            ['fraction' => 0.0, 'weight' => 1],
        ]);

        $this->assertSame(3.0, $result['raw']);
        $this->assertSame(4.0, $result['max']);
        $this->assertSame(75.0, $result['scaled']); // 3/4 * 100
    }
}
