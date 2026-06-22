# 11 — Analytics & Psychometrics Module

Computes classical-test-theory statistics from the real sitting data produced by
Delivery & Scoring, and **closes the authoring feedback loop**: each item's measured
difficulty is written back to the bank so future blueprint draws band items by
empirical difficulty rather than a guess.

Covered by **11 analytics tests**, including the math checked against hand-computed
values (whole suite: **73 passing**).

## Code layout

```
app/Modules/Analytics/
  Models/        ItemStatistics, AssessmentReliability   (read models)
  Services/
    PsychometricsCalculator.php   pure CTT math (arrays in, numbers out)
    AnalyticsService.php          gather finalized data, compute, persist, feed back
  Http/Controllers/AnalyticsController.php
```

## What it computes (docs/01 §4.8)

**Per item** (`item_statistics`):
- **Facility index** (p) — proportion answering correctly.
- **Discrimination index** — point-biserial correlation between the item (0/1) and the
  candidate's total score.
- **Distractor analysis** — per option: chosen count, proportion, and whether it's the
  keyed answer (the wrong-answer key is read from the vault for marking).

**Per assessment** (`assessment_reliability`):
- **KR-20** — `k/(k−1)·(1 − Σ p·q / σ²_total)`.
- **Cronbach's α** — general form (equals KR-20 for dichotomous items).
- **SEM** — `σ_total · √(1 − reliability)`.

The math lives in `PsychometricsCalculator` as **pure functions**, unit-tested against a
hand-computed 3-item/4-candidate dataset (facility `[.75,.50,.25]`, discrimination
`0.7746`, KR-20 `0.75`, SEM `0.559`) — and the full delivery→scoring→analytics pipeline
reproduces exactly those numbers in `AnalyticsPipelineTest`.

## The feedback loop

`compileAssessment()`:
1. gathers the assessment's **graded** sittings (off the hot path — read models only),
2. rebuilds the correctness matrix by reusing `ScoringService::analyzeSitting()` (the
   same per-item, vault-backed analysis used at scoring time — single source of truth),
3. computes and persists item statistics + reliability,
4. **writes measured `difficulty` and `discrimination` back onto `items`** — so
   Authoring's `DifficultyBand` (which reads `items.difficulty`) now bands by real data.

An item is scored dichotomously for CTT (correct iff fraction == 1), the standard basis
for KR-20.

## API (under `/api/analytics`, authenticated)

| Method & path | Permission | Purpose |
|---------------|-----------|---------|
| `POST /assessments/{id}/compile` | `analytics.compute` | recompute stats from graded sittings |
| `GET /assessments/{id}/reliability` | `analytics.read` | KR-20 / α / SEM |
| `GET /items/{id}/statistics` | `analytics.read` | facility / discrimination / distractors |

`exam_officer` holds both permissions; a `student` is forbidden (403, tested).

## Notes / future work

- IRT parameter estimation (`item_statistics.irt_params` exists in the schema) is a later
  phase; this module ships classical test theory.
- Candidate-level analytics (trends, competency gaps), cohort/department/faculty
  comparisons, and national benchmarking build on these same read models.
- Compilation runs synchronously here; at scale it becomes a queued job triggered by the
  `ScoreFinalized` event (docs/02 §2).
