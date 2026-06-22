<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TOTP MFA enrolment and the login challenge/verify flow. */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_totp_service_generates_and_verifies_codes(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $code = $totp->codeAt($secret, intdiv(time(), 30));

        $this->assertTrue($totp->verify($secret, $code));
        $this->assertFalse($totp->verify($secret, '000000'));
    }

    public function test_enroll_then_login_requires_and_accepts_mfa(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst, 'mfa@example.test');
        $totp = app(TotpService::class);

        // Enrol TOTP.
        $enroll = $this->postJson('/api/auth/mfa/enroll', [], $this->authHeaders($user));
        $enroll->assertOk()->assertJsonStructure(['secret', 'uri']);
        $secret = $enroll->json('secret');

        // Confirm enrolment with a valid code -> MFA becomes required.
        $confirmCode = $totp->codeAt($secret, intdiv(time(), 30));
        $this->postJson('/api/auth/mfa/confirm', ['code' => $confirmCode], $this->authHeaders($user))
            ->assertOk()->assertJsonPath('confirmed', true);

        // Now a fresh login returns an MFA challenge instead of a token.
        $login = $this->postJson('/api/auth/login', ['email' => 'mfa@example.test', 'password' => 'secret']);
        $login->assertOk()->assertJsonPath('status', 'mfa_required');
        $challenge = $login->json('challenge');

        // Verifying the challenge with a TOTP code yields a token.
        $loginCode = $totp->codeAt($secret, intdiv(time(), 30));
        $this->postJson('/api/auth/mfa/verify', ['challenge' => $challenge, 'code' => $loginCode])
            ->assertCreated()->assertJsonPath('status', 'authenticated');
    }

    public function test_wrong_mfa_confirmation_code_is_rejected(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);

        $this->postJson('/api/auth/mfa/enroll', [], $this->authHeaders($user))->assertOk();
        $this->postJson('/api/auth/mfa/confirm', ['code' => '000000'], $this->authHeaders($user))
            ->assertStatus(422)->assertJsonPath('confirmed', false);
    }
}
