<?php

namespace Tests\Feature\Notifications;

use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP: sending, own-vs-all visibility, and permission enforcement. */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    private function tenant(): Institution
    {
        $inst = Institution::create(['name' => 'N U', 'slug' => 'n-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);

        return $inst;
    }

    public function test_officer_sends_and_recipient_sees_only_their_own(): void
    {
        $inst = $this->tenant();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $alice = $this->makeUser($inst);
        $this->grantRole($alice, 'student');
        $bob = $this->makeUser($inst);
        $this->grantRole($bob, 'student');

        // Officer sends one to Alice, one to Bob.
        $this->postJson('/api/notifications', [
            'recipient_id' => $alice->id, 'channel' => 'email', 'event_key' => 'announcement',
            'context' => ['subject' => 'Hello Alice', 'body' => '...'],
        ], $this->authHeaders($officer))->assertCreated()->assertJsonPath('status', 'sent');

        $this->postJson('/api/notifications', [
            'recipient_id' => $bob->id, 'channel' => 'sms', 'event_key' => 'announcement',
            'context' => ['subject' => 'Hello Bob', 'body' => '...'],
        ], $this->authHeaders($officer))->assertCreated();

        // Alice sees only hers; officer sees all.
        $this->getJson('/api/notifications', $this->authHeaders($alice))->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/notifications', $this->authHeaders($officer))->assertOk()->assertJsonPath('total', 2);
    }

    public function test_student_cannot_send(): void
    {
        $inst = $this->tenant();
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson('/api/notifications', [
            'recipient_id' => $student->id, 'channel' => 'email', 'event_key' => 'announcement',
        ], $this->authHeaders($student))->assertStatus(403);
    }

    public function test_invalid_channel_is_422(): void
    {
        $inst = $this->tenant();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');

        $this->postJson('/api/notifications', [
            'recipient_id' => $officer->id, 'channel' => 'pigeon', 'event_key' => 'announcement',
        ], $this->authHeaders($officer))->assertStatus(422);
    }
}
