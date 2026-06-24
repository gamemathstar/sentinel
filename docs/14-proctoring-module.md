# 14 — Proctoring Module

Monitoring tied to sittings, with an **explainable risk score** that routes to human
review and never auto-decides (docs/05). A session opens automatically when a proctored
exam starts; flags and evidence flow in; risk is computed from them and is fully
reconstructable.

Covered by **14 proctoring tests**, including the risk math checked against hand-computed
values (whole suite: **106 passing**).

## Code layout

```
app/Modules/Proctoring/
  Models/        ProctoringSession, ProctoringFlag, EvidenceClip, RiskAssessment
  Support/FlagCatalog.php             flag types + default risk weights
  Services/
    RiskScoringEngine.php             pure, explainable risk aggregation
    ProctoringService.php             open / flag / evidence / assess / review
  Listeners/OpenProctoringSessionOnSittingStarted.php
  Events/{FlagRaised, RiskAssessed}.php  (+ Delivery\Events\SittingStarted)
  Exceptions/ProctoringError.php
  Http/Controllers/ProctoringController.php
```

## Explainable risk scoring (the substantive part)

`RiskScoringEngine` is **pure** (flags + weights in → score + timeline out) so the
calibration is testable and every score is reconstructable. It combines evidence with
**noisy-OR**:

- Per flag type, repeated detections combine `conf = 1 − Π(1 − confidenceᵢ)`; the type's
  contribution is `weight · conf`, so a lone weak flag stays small.
- Across types, `probability = 1 − Π(1 − contribution)`, so independent strong signals
  **corroborate** and reinforce each other.

The result carries a `timeline` ordered by contribution — the per-type weight, combined
confidence, occurrence count, and the **specific flag ids** that contributed. That is
what an invigilator, a QA officer, and an appeals board read; there is no opaque verdict.
Hand-computed check (verified): phone .95 + two face-absent (.9,.8) + tab .1.0 ⇒
probability ≈ **0.896**, with `phone_detected` topping the timeline; a lone `tab_switch`
.3 ⇒ **0.045** (below the 0.6 review threshold). Calibration against reviewed outcomes is
future work.

## Lifecycle

- **Auto-open.** `SittingStarted` → a session opens *only* if the assessment's proctoring
  policy monitors (`mode != none`); unproctored exams open none (both tested).
- **Ingest.** Flags (typed, confidence-scored, sourced client/edge/server_inference) and
  evidence clips attach to the session. Unknown flag types are rejected (422).
- **Assess.** Recomputes the `RiskAssessment` (status `auto`) and emits `RiskAssessed`
  with a `requires_review` signal derived from the policy threshold.
- **Review, never auto-void.** A high score routes a session to the QA queue; a human
  records `cleared`/`upheld`. The sitting is **not** voided by the score itself — verified
  by test (sitting stays `in_progress` under high risk). Voiding remains a separate,
  audited admin action.

## API (under `/api/proctoring`, authenticated)

| Method & path | Permission | Purpose |
|---------------|-----------|---------|
| `POST /sittings/{id}/session` | `proctoring.monitor` | open a session |
| `POST /sessions/{id}/flags` | `proctoring.monitor` | ingest a flag |
| `POST /sessions/{id}/assess` | `proctoring.monitor` | (re)compute risk |
| `GET /sessions/{id}` | `proctoring.monitor` | session + flags + risk |
| `GET /review-queue` | `proctoring.review` | high-risk sessions awaiting review |
| `POST /risk/{id}/review` | `proctoring.review` | record cleared/upheld |

A new `proctor` role holds `proctoring.monitor`; `exam_officer` (QA) holds both monitor
and review. Tested: a `student` cannot flag (403), and a `proctor` cannot open the review
queue (403).

## Notes / future work

- The client signal SDK, the lockdown browser, and the Python GPU inference service are
  designed in docs/05 (tiered signal pipeline); this module is the server-side ingest +
  scoring + review plane they feed. Inference is reached through a contract, like the AI
  grader, when it lands.
- Identity-verification match scores live on `proctoring_sessions.identity_verification`;
  continuous face/voice matching populates them in a later phase.
- Evidence media encryption + retention shredding (docs/05 §9) and the live invigilator
  WebSocket grid (docs/05 §8) build on these models.
