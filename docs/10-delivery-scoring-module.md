# 10 — Exam Delivery & Scoring Module

The hot path (docs/02) and the **loop closure**: a candidate sits a published assessment,
answers are recorded append-only, and at submit time the **vault is queried just-in-time**
to score against the answer keys, applying the authoring `ScoringPolicy`. This connects
every prior module — security (vault), Question Bank, Authoring, and IAM.

Covered by **10 delivery tests** (whole suite: **61 passing**).

## Code layout

```
app/Modules/Delivery/
  Models/        Sitting, VariantManifest, Response, Score, GradingTask
  Services/
    VariantAssembler.php   per-candidate randomized paper + candidate-facing presentation
    SittingService.php     assign (one-per-candidate) + start (server deadline)
    ResponseRecorder.php   append-only writes, latest-sequence-wins, deadline guard
    ScoringService.php     submit -> JIT vault scoring -> ScoringPolicy -> Score
  Events/{SittingSubmitted, ScoreFinalized}.php
  Exceptions/DeliveryError.php
  Policies/SittingPolicy.php
  Http/Controllers/SittingController.php
```

## The flow

```
assign (staff)  ->  start (candidate)  ->  respond* (candidate)  ->  submit (candidate)
   |                    |                       |                        |
 builds a            sets a              append-only rows          JIT vault scoring
 per-candidate       SERVER-authoritative  (latest seq wins)       + ScoringPolicy
 variant manifest    deadline                                      => Score (+events)
```

## Properties that matter (each verified by test)

- **Per-candidate variants + per-session answer mapping.** `VariantAssembler` shuffles
  question order (within sections) and option order per candidate, recording the
  display→canonical option mapping in the manifest. The candidate sees options by
  position only; the candidate-facing endpoint **leaks no correctness** (asserted). So
  "the answer is B" means nothing across candidates (docs/04 §8).
- **Server-authoritative deadline.** `start()` stamps `server_deadline_epoch`; responses
  after it are rejected. The client clock never decides (docs/04 §8).
- **Append-only responses, latest-wins.** Correcting an answer writes a new row with a
  higher sequence; both rows persist and the newest is what scoring reads (docs/03 §4) —
  the basis for trivial offline conflict resolution.
- **Just-in-time vault scoring.** At submit, for each objective item the candidate's
  shuffled answer is mapped back to canonical keys via the manifest, scored by
  `AnswerKeyVault` (the answer key never co-located with the question), then run through
  the authoring `ScoringPolicy` — positive/negative/partial/blank all honoured (negative
  marking verified: +4/−1 ⇒ 3).
- **Reproducible scores.** The `Score` pins the scoring-rule version (asserted), so it is
  reproducible from `(responses, rule@version)`.
- **One sitting per candidate per assessment** (service check + DB unique index).
- **Open-ended items** (essay/code/short-answer) spawn a `GradingTask` and leave the
  score `under_review` instead of auto-scoring.
- **Ownership & permissions.** A candidate can only act on their **own** sitting (403
  otherwise); only staff with `delivery.sitting.assign` can assign; `student` cannot
  assign — all tested.

## API (under `/api/delivery`, authenticated)

| Method & path | Who | Purpose |
|---------------|-----|---------|
| `POST /assessments/{id}/sittings` | staff (`sitting.assign`) | assign a candidate; pre-assembles the variant |
| `POST /sittings/{id}/start` | owning candidate | begin; sets the server deadline |
| `GET /sittings/{id}` | owning candidate | fetch the paper (display order, no answers) |
| `POST /sittings/{id}/responses` | owning candidate | record/correct an answer (append-only) |
| `POST /sittings/{id}/submit` | owning candidate | submit + score |
| `GET /sittings/{id}/score` | candidate (own) / staff | view the score |

## Resilience: resume after failure + extra time

- **Answers are crash-durable.** Every answer is an append-only row written as the
  candidate goes (latest sequence wins), so an internet/power failure loses nothing.
- **Resume** (`POST /sittings/{id}/resume`, candidate) restores the paper, the candidate's
  saved answers, and the **remaining server-authoritative time** — because the deadline is
  an absolute server epoch, reconnecting preserves the clock rather than resetting it. The
  reconnection is recorded in `sync_meta`.
- **Grant extra time** (`POST /sittings/{id}/extend`, staff with `delivery.sitting.assign`)
  extends the deadline by N minutes — for an accommodation or to compensate for an outage —
  and **reopens a deadline that had already lapsed** (extends from now). Each grant is
  recorded in `sync_meta.extensions[]` with reason + grantor; a submitted sitting can't be
  extended. Verified by `ResumeAndExtendTest`.

## Events (integration seams)

`SittingSubmitted` and `ScoreFinalized` are dispatched for downstream contexts
(Proctoring finalize, Analytics, Certification, Notifications) to subscribe to later.

## Notes / future work

- Scoring runs synchronously inside the submit transaction here; at national scale it
  moves to a queue worker (docs/02 §2) — the service boundary already supports that.
- Manual/AI grading of `GradingTask`s (double-marking + reconciliation) and the
  Redis response write-buffer / pre-assembly-at-scale optimisations are later phases.
- Adaptive/branching delivery (response-gated item unlocking) builds on this base.
