<?php

namespace App\Modules\Notifications\Services;

/**
 * Renders a subject/body for an event from its context (docs spec: exam reminders,
 * result notices, announcements, emergency alerts). Templates are intentionally simple
 * string interpolation; a richer templating engine can replace this without changing
 * the service contract.
 */
class NotificationComposer
{
    public function compose(string $eventKey, array $context): array
    {
        return match ($eventKey) {
            'result_ready' => [
                'subject' => 'Your result is ready',
                'body' => "Your result for \"{$context['assessment']}\" is available. Score: {$context['raw_score']}.",
            ],
            'exam_reminder' => [
                'subject' => 'Upcoming exam reminder',
                'body' => "Reminder: \"{$context['assessment']}\" opens at {$context['opens_at']}.",
            ],
            'certificate_issued' => [
                'subject' => 'Your certificate has been issued',
                'body' => "Certificate {$context['serial']} for \"{$context['assessment']}\" is now verifiable.",
            ],
            'announcement', 'emergency_alert' => [
                'subject' => $context['subject'] ?? ucfirst(str_replace('_', ' ', $eventKey)),
                'body' => $context['body'] ?? '',
            ],
            default => [
                'subject' => $context['subject'] ?? ucfirst(str_replace('_', ' ', $eventKey)),
                'body' => $context['body'] ?? '',
            ],
        };
    }
}
