<?php

namespace Tests\Feature\Proctoring;

use App\Modules\Proctoring\Services\RiskScoringEngine;
use Tests\TestCase;

/**
 * The pure risk math, checked against hand-computed values. Catalog weights:
 * face_absent .5, phone_detected .8, tab_switch .15.
 */
class RiskScoringEngineTest extends TestCase
{
    private RiskScoringEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new RiskScoringEngine;
    }

    public function test_noisy_or_aggregation_with_corroborating_signals(): void
    {
        $flags = [
            ['id' => 'a', 'type' => 'face_absent', 'confidence' => 0.9],
            ['id' => 'b', 'type' => 'face_absent', 'confidence' => 0.8],
            ['id' => 'c', 'type' => 'phone_detected', 'confidence' => 0.95],
            ['id' => 'd', 'type' => 'tab_switch', 'confidence' => 1.0],
        ];

        $r = $this->engine->assess($flags);

        // face_absent combined conf = 1-(0.1*0.2)=0.98 -> contrib 0.49 ; phone 0.76 ; tab 0.15
        // prob = 1-(1-.49)(1-.76)(1-.15) = 0.89596
        $this->assertEqualsWithDelta(0.896, $r['cheating_probability'], 1e-3);
        $this->assertEqualsWithDelta(1.40, $r['suspicion_score'], 1e-3);
        $this->assertTrue($r['requires_review']);

        // Timeline is explainable and ordered by contribution (phone first).
        $this->assertSame('phone_detected', $r['timeline'][0]['type']);
        $this->assertSame('tab_switch', $r['timeline'][2]['type']);
        $faceAbsent = collect($r['timeline'])->firstWhere('type', 'face_absent');
        $this->assertEqualsWithDelta(0.98, $faceAbsent['combined_confidence'], 1e-4);
        $this->assertSame(2, $faceAbsent['occurrences']);
        $this->assertSame(['a', 'b'], $faceAbsent['flag_ids']);
    }

    public function test_a_lone_weak_signal_stays_low(): void
    {
        $r = $this->engine->assess([['id' => 'x', 'type' => 'tab_switch', 'confidence' => 0.3]]);

        $this->assertEqualsWithDelta(0.045, $r['cheating_probability'], 1e-4); // 0.15 * 0.3
        $this->assertFalse($r['requires_review']);
    }

    public function test_no_flags_is_zero_risk(): void
    {
        $r = $this->engine->assess([]);
        $this->assertSame(0.0, $r['cheating_probability']);
        $this->assertFalse($r['requires_review']);
        $this->assertSame([], $r['timeline']);
    }

    public function test_policy_weight_overrides_are_honoured(): void
    {
        $flags = [['id' => 'x', 'type' => 'tab_switch', 'confidence' => 1.0]];
        $r = $this->engine->assess($flags, ['tab_switch' => 0.9]);
        $this->assertEqualsWithDelta(0.9, $r['cheating_probability'], 1e-9);
    }
}
