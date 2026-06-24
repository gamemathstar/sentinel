# 16 — Notifications Module

Multi-channel (email / SMS / push / WhatsApp), **idempotent**, event-driven notifications.
The headline integration: when a score finalizes, the candidate is automatically told
their result is ready — exactly once.

Covered by **9 notification tests** (whole suite: **121 passing**).

## Code layout

```
app/Modules/Notifications/
  Models/Notification.php
  Contracts/NotificationTransport.php       anti-corruption interface to providers
  Transports/LogTransport.php               PLACEHOLDER delivery (logs)
  Services/
    NotificationComposer.php                event_key -> subject/body
    NotificationService.php                 idempotent send + deliver
  Listeners/NotifyCandidateOnScoreFinalized.php
  Http/Controllers/NotificationController.php
```

## Idempotent, multi-channel sending

`NotificationService.send(recipient, channel, eventKey, context, dedupeKey?)`:

1. validates the channel (email/sms/push/whatsapp),
2. composes a subject/body from the event template,
3. `firstOrCreate` by the unique **`dedupe_key`** — so a re-send for the same
   (recipient, event) returns the existing record and **does not deliver twice**
   (verified by test: two sends ⇒ one row),
4. delivers via the channel **transport** and marks `sent`/`failed`.

Delivery goes through the `NotificationTransport` contract; the dev binding is
`LogTransport` (logs), swapped per channel for real providers (SES / Twilio / FCM /
WhatsApp Cloud API) without touching the service — the same anti-corruption pattern used
for AI grading and certificate anchoring.

## Event-driven

`NotifyCandidateOnScoreFinalized` subscribes to `ScoreFinalized` and sends a
`result_ready` email — but only when the score is truly **final** (the event also fires
for `under_review` scores), idempotent per sitting. Verified end-to-end: running an
objective sitting to submission produces exactly one `result_ready` notification to the
candidate with the rendered subject/body.

Other templated events ready in the composer: `exam_reminder`, `certificate_issued`,
`announcement`, `emergency_alert`.

## API (under `/api/notifications`, authenticated)

| Method & path | Access | Purpose |
|---------------|--------|---------|
| `GET /` | any authenticated | a recipient sees **their own**; a sender (`notifications.send`) sees all |
| `POST /` | `notifications.send` | dispatch a notification (idempotent) |

Verified: a recipient's list is scoped to themselves while an officer sees all; a
`student` cannot send (403); an unknown channel is 422.

## Notes / future work

- Delivery is synchronous here; at scale `send` becomes a queued job (Redis), with retries
  and a dead-letter for `failed`.
- Per-channel transports, delivery receipts/read tracking, user notification preferences,
  and announcement broadcasts (one event → many recipients) build on this base.
