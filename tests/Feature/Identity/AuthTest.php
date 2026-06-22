<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Password login, token sessions, me, logout, and rejection of bad/revoked tokens. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_login_issues_a_bearer_token(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst, 'login@example.test');

        $resp = $this->postJson('/api/auth/login', ['email' => 'login@example.test', 'password' => 'secret']);

        $resp->assertCreated()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $inst = $this->makeTenant();
        $this->makeUser($inst, 'wrong@example.test');

        $this->postJson('/api/auth/login', ['email' => 'wrong@example.test', 'password' => 'nope'])
            ->assertStatus(401);
    }

    public function test_me_returns_user_and_resolved_permissions(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);
        $this->grantRole($user, 'question_author');

        $this->getJson('/api/auth/me', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonFragment(['questionbank.item.create']);
    }

    public function test_logout_revokes_the_token(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/auth/me', $headers)->assertOk();
        $this->postJson('/api/auth/logout', [], $headers)->assertOk();
        // Token no longer works after revocation.
        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
    }

    public function test_garbage_token_is_rejected(): void
    {
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer not-a-real-token'])->assertStatus(401);
    }
}
