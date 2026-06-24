<?php

namespace Tests\Feature\Certification;

use App\Modules\Certification\Models\Certificate;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP: public verification portal (no auth) + permissioned issue/revoke. */
class CertificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    /** @return array{0:Institution,1:Sitting} */
    private function finalizedSitting(): array
    {
        $inst = Institution::create(['name' => 'Cert U', 'slug' => 'cert-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);
        $sitting = $this->runSitting($assessment, $this->makeUser($inst), [$ivs[0] => true, $ivs[1] => true]);

        return [$inst, $sitting];
    }

    public function test_public_verification_requires_no_authentication(): void
    {
        [$inst] = $this->finalizedSitting();
        // A certificate was auto-issued on finalization.
        $cert = Certificate::withoutGlobalScopes()->first();

        // No Authorization header at all — the token is the credential.
        $this->getJson("/api/certification/verify/{$cert->verification_token}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('serial', $cert->serial);

        $this->getJson('/api/certification/verify/not-a-real-token')
            ->assertStatus(404)
            ->assertJsonPath('valid', false);
    }

    public function test_officer_can_issue_and_revoke(): void
    {
        [$inst, $sitting] = $this->finalizedSitting();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $headers = $this->authHeaders($officer);

        // Issue is idempotent with the auto-issued one; returns a certificate either way.
        $issue = $this->postJson("/api/certification/sittings/{$sitting->id}/issue", [], $headers)
            ->assertCreated()->json();

        $cert = Certificate::withoutGlobalScopes()->where('verification_token', $issue['verification_token'])->firstOrFail();

        $this->postJson("/api/certification/certificates/{$cert->id}/revoke", [], $headers)
            ->assertOk()->assertJsonPath('revoked', true);

        // After revocation the public portal reports it invalid.
        $this->getJson("/api/certification/verify/{$cert->verification_token}")
            ->assertStatus(404)->assertJsonPath('reason', 'revoked');
    }

    public function test_student_cannot_issue_certificates(): void
    {
        [$inst, $sitting] = $this->finalizedSitting();
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson("/api/certification/sittings/{$sitting->id}/issue", [], $this->authHeaders($student))
            ->assertStatus(403);
    }
}
