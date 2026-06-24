<?php

namespace Tests\Feature\Certification;

use App\Modules\Certification\Exceptions\CertificationError;
use App\Modules\Certification\Models\Certificate;
use App\Modules\Certification\Services\CertificationService;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\SittingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Issuing, public verification, tamper-evidence, revocation, idempotency, anchoring. */
class CertificationTest extends TestCase
{
    use RefreshDatabase;

    /** Run an objective sitting to completion so its score is final. */
    private function finalizedSitting(): Sitting
    {
        $inst = $this->makeTenant();
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);

        return $this->runSitting($assessment, $this->makeUser($inst), [$ivs[0] => true, $ivs[1] => true]);
    }

    public function test_issue_creates_a_verifiable_certificate(): void
    {
        $sitting = $this->finalizedSitting();
        $cert = app(CertificationService::class)->issueForSitting($sitting);

        $this->assertNotEmpty($cert->serial);
        $this->assertNotEmpty($cert->verification_token);
        $this->assertSame($sitting->candidate_id, $cert->candidate_id);

        $result = app(CertificationService::class)->verify($cert->verification_token);
        $this->assertTrue($result['valid']);
        $this->assertSame($cert->serial, $result['serial']);
        $this->assertEqualsWithDelta(2.0, $result['payload']['result']['raw_score'], 1e-9);
    }

    public function test_issuing_is_idempotent(): void
    {
        $sitting = $this->finalizedSitting();
        $svc = app(CertificationService::class);

        $a = $svc->issueForSitting($sitting);
        $b = $svc->issueForSitting($sitting);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Certificate::where('assessment_id', $sitting->assessment_id)->count());
    }

    public function test_tampering_with_the_snapshot_is_detected(): void
    {
        $sitting = $this->finalizedSitting();
        $cert = app(CertificationService::class)->issueForSitting($sitting);

        // Mutate the stored snapshot directly (simulating a DB-level tamper).
        $payload = $cert->payload;
        $payload['result']['raw_score'] = 999;
        $cert->forceFill(['payload' => $payload])->save();

        $result = app(CertificationService::class)->verify($cert->verification_token);
        $this->assertFalse($result['valid']);
        $this->assertSame('tampered', $result['reason']);
    }

    public function test_revoked_certificate_does_not_verify(): void
    {
        $sitting = $this->finalizedSitting();
        $svc = app(CertificationService::class);
        $cert = $svc->issueForSitting($sitting);

        $svc->revoke($cert);

        $result = $svc->verify($cert->verification_token);
        $this->assertFalse($result['valid']);
        $this->assertSame('revoked', $result['reason']);
    }

    public function test_unknown_token_is_invalid(): void
    {
        $this->makeTenant();
        $result = app(CertificationService::class)->verify('deadbeef');
        $this->assertFalse($result['valid']);
        $this->assertSame('not_found', $result['reason']);
    }

    public function test_cannot_issue_for_a_non_final_score(): void
    {
        $inst = $this->makeTenant();
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);
        // Assigned but never submitted -> no final score.
        $sitting = app(SittingService::class)
            ->assign($assessment, $this->makeUser($inst));

        $this->expectException(CertificationError::class);
        app(CertificationService::class)->issueForSitting($sitting);
    }

    public function test_optional_anchor_records_a_transaction_id(): void
    {
        $sitting = $this->finalizedSitting();
        $cert = app(CertificationService::class)->issueForSitting($sitting, anchor: true);

        $this->assertNotNull($cert->anchor_txid);
        $this->assertStringStartsWith('localledger:', $cert->anchor_txid);
        $this->assertTrue(app(CertificationService::class)->verify($cert->verification_token)['anchored']);
    }

    public function test_certificate_is_auto_issued_when_score_becomes_final(): void
    {
        // The ScoreFinalized listener should have issued a certificate during runSitting().
        $sitting = $this->finalizedSitting();

        app(TenantContext::class)->actAsPlatform();
        $this->assertSame(1, Certificate::where('assessment_id', $sitting->assessment_id)->count());
    }
}
