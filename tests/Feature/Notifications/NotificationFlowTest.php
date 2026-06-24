<?php

namespace Tests\Feature\Notifications;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** Idempotent sending, channel validation, and event-driven result notifications. */
class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_is_idempotent_per_dedupe_key(): void
    {
        $inst = $this->makeTenant();
        $recipient = $this->makeUser($inst);
        $svc = app(NotificationService::class);

        $a = $svc->send($recipient->id, 'email', 'announcement', ['subject' => 'Hi', 'body' => 'Welcome'], 'dk-1');
        $b = $svc->send($recipient->id, 'email', 'announcement', ['subject' => 'Hi', 'body' => 'Welcome'], 'dk-1');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Notification::count());
        $this->assertSame('sent', $a->fresh()->status);
    }

    public function test_supports_all_channels_and_rejects_unknown(): void
    {
        $inst = $this->makeTenant();
        $recipient = $this->makeUser($inst);
        $svc = app(NotificationService::class);

        foreach (['email', 'sms', 'push', 'whatsapp'] as $i => $channel) {
            $n = $svc->send($recipient->id, $channel, 'announcement', ['subject' => 'X', 'body' => 'Y'], "ch-$i");
            $this->assertSame('sent', $n->status);
            $this->assertSame($channel, $n->channel);
        }

        $this->expectException(InvalidArgumentException::class);
        $svc->send($recipient->id, 'pigeon', 'announcement', []);
    }

    public function test_result_ready_notification_is_sent_when_a_score_finalizes(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2);
        $ivs = array_map(fn ($i) => $i->current_version_id, $items);

        // Objective-only sitting -> score is final on submit -> ScoreFinalized -> listener.
        $this->runSitting($assessment, $candidate, [$ivs[0] => true, $ivs[1] => true]);

        $notification = Notification::where('recipient_id', $candidate->id)
            ->where('event_key', 'result_ready')->first();

        $this->assertNotNull($notification);
        $this->assertSame('sent', $notification->status);
        $this->assertSame('email', $notification->channel);
        $this->assertSame('Your result is ready', $notification->payload['subject']);
        $this->assertStringContainsString('Score:', $notification->payload['body']);
    }
}
