# 09 — Assessment Authoring Module

Turns the question bank into deliverable exams: scoring rules, blueprints, and
**automatic blueprint-driven assembly of balanced papers**, plus a validated publish
lifecycle. Built on the IAM permission model, so every action is gated.

Covered by **15 authoring tests** (whole suite: **51 passing**).

## Code layout

```
app/Modules/Authoring/
  Models/        Assessment, AssessmentSection, Blueprint, ScoringRule,
                 ProctoringPolicy, ScoringPolicy (pure evaluator)
  Services/
    ScoringRuleService.php   create + version scoring rules
    BlueprintService.php     create + satisfiability validation
    PaperAssembler.php       blueprint -> balanced draw from the bank
    AssessmentService.php    lifecycle: create, sections, pin, assemble, publish
  Support/DifficultyBand.php facility -> easy/medium/hard
  Events/AssessmentPublished.php
  Exceptions/{AssemblyShortfall, PublishValidationFailed}.php
  Policies/AssessmentPolicy.php
  Http/Controllers/{Assessment,Blueprint,ScoringRule}Controller.php
```

## Blueprint-driven assembly (the heart of the module)

A blueprint declares the desired composition:

```json
{ "total": 20, "difficulty": {"easy":0.4,"medium":0.4,"hard":0.2}, "types": ["single","multiple"] }
```

`PaperAssembler` draws **only approved items** (status = active, with a pinned current
version) from the tenant's bank, buckets them by difficulty band (from each item's
facility index), and apportions the `total` across bands with the **largest-remainder
method** so the per-band counts sum exactly. If a band can't be filled it raises
`AssemblyShortfall` with a per-band `{needed, available}` breakdown — it never silently
produces an unbalanced paper. Verified by test: a 40/40/20 blueprint over a stocked bank
yields exactly 4/4/2.

## Scoring rules + pure evaluator

`ScoringRule` is versioned (editing creates a new version, so a score can pin the exact
rule that produced it). `ScoringPolicy` is a **pure, side-effect-free evaluator** —
positive/negative/blank marks, optional partial credit, per-question weights, optional
rescale. It lives here because the rule is authored here, and the **Delivery/Scoring
module reuses it at submit time** so a score is reproducible from `(responses, policy)`.

| Example | correct | wrong | blank | result for 3✓/1✗/1∅ |
|---------|--------|-------|-------|----------------------|
| +4/−1/0 | 4 | −1 | 0 | raw 11, max 20 |

## Lifecycle & reproducibility

`draft → published → live → closed → archived`. `publish()` validates the assessment is
deliverable (has a scoring rule, ≥1 section, every section has items, sane window) and
raises `PublishValidationFailed` with the reasons otherwise. On success it flips status
and dispatches `AssessmentPublished` — the integration seam Delivery/Notifications will
subscribe to.

Sections pin **item _versions_**, never items. A test proves that editing an item after
it's pinned creates a new version and moves the bank's pointer, **but the section still
references the originally pinned version** — so a published paper is reproducible forever
(docs/03 §3). A published assessment is no longer editable.

## API (under `/api/authoring`, authenticated + tenant-scoped)

| Method & path | Permission | Purpose |
|---------------|-----------|---------|
| `POST /scoring-rules` | `authoring.scoringrule.manage` | create a scoring rule |
| `POST /blueprints` | `authoring.blueprint.manage` | create a blueprint (validated) |
| `POST /assessments` | `authoring.assessment.create` | create a draft assessment |
| `POST /assessments/{id}/sections` | `authoring.assessment.update` | add a section |
| `POST /assessments/{id}/sections/{s}/assemble` | `authoring.assessment.update` | fill a section from a blueprint |
| `POST /assessments/{id}/publish` | `authoring.assessment.publish` | validate + publish |

The `exam_officer` system role carries all authoring permissions; a `student` is
forbidden (403, tested). Shortfall and publish-validation failures return **422** with
machine-readable detail.

## Notes / future work

- Topic/Bloom distribution in blueprints is validated but assembly currently balances by
  difficulty band; topic-aware drawing is a straightforward extension of `PaperAssembler`.
- Per-candidate randomization (option order, equivalent-variant selection) is the Exam
  Delivery module's responsibility — Authoring produces the section's pinned pool.
