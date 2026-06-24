<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end HTTP coverage of the Question Bank API under real bearer-token auth. */
class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    /** @return array{0:Institution,1:User} */
    private function tenantAndAdmin(): array
    {
        $inst = Institution::create(['name' => 'API U', 'slug' => 'api-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $user = $this->makeUser($inst);
        $this->grantRole($user, 'institution_admin'); // all QB permissions within the tenant

        return [$inst, $user];
    }

    public function test_create_and_list_items_without_exposing_answers(): void
    {
        [, $admin] = $this->tenantAndAdmin();
        $headers = $this->authHeaders($admin);
        $bank = $this->makeBank();

        $create = $this->postJson('/api/question-bank/items', [
            'type' => 'single',
            'question_bank_id' => $bank->id,
            'content' => ['stem' => 'HTTP Q?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['b']],
        ], $headers);

        $create->assertCreated();
        $this->assertStringNotContainsStringIgnoringCase('correct', json_encode($create->json('current_version.content')));

        $list = $this->getJson('/api/question-bank/items', $headers);
        $list->assertOk();
        $this->assertSame(1, $list->json('total'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/question-bank/items', [
            'type' => 'single',
            'content' => ['stem' => 'x', 'options' => ['a' => '1']],
        ])->assertStatus(401);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $inst = Institution::create(['name' => 'NoPerm U', 'slug' => 'np-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student'); // no question-bank permissions
        $bank = $this->makeBank();

        $this->postJson('/api/question-bank/items', [
            'type' => 'single',
            'question_bank_id' => $bank->id,
            'content' => ['stem' => 'x', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ], $this->authHeaders($student))->assertStatus(403);
    }

    public function test_validation_rejects_unknown_type(): void
    {
        [, $admin] = $this->tenantAndAdmin();

        $this->postJson('/api/question-bank/items', [
            'type' => 'telepathy',
            'content' => ['stem' => 'x'],
        ], $this->authHeaders($admin))->assertStatus(422);
    }

    public function test_import_endpoint(): void
    {
        [, $admin] = $this->tenantAndAdmin();

        $raw = "?? HTTP import?\n** Wrong\n** Right ==";
        $resp = $this->postJson('/api/question-bank/items/import', [
            'format' => 'legion',
            'body' => $raw,
        ], $this->authHeaders($admin));

        $resp->assertCreated();
        $this->assertSame(1, $resp->json('created'));
    }
}
