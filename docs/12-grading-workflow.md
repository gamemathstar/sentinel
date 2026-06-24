# 12 — Manual / AI Grading Workflow

Completes the scoring story. Open-ended items (essay, short-answer, code) can't be
auto-scored against the vault, so at submit they spawn **grading tasks** and the score
sits `under_review`. This module marks those tasks — double human marking with
reconciliation, plus an advisory AI suggestion — and folds the result back into the
score, taking it to `final`.

Covered by **11 grading tests** (whole suite: **83 passing**).

## Code layout

```
app/Modules/Delivery/
  Models/        GradingTask (+ marks/humanMarks), GradingMark
  Contracts/AiGrader.php              anti-corruption interface to an AI grader
  Services/
    HeuristicAiGrader.php             deterministic PLACEHOLDER implementation
    GradingService.php                double-marking, SoD, reconcile, fold into Score
  Exceptions/GradingError.php
  Http/Controllers/GradingController.php
database/migrations/...add_item_version_to_grading_tasks.php
```

## The workflow

```
submit (essay answered)  ->  GradingTask(pending) + Score(under_review)
        |
   mark #1 (grader A) -> double_marking
        |
   mark #2 (grader B):
       agree (≤ tolerance) -> auto-reconcile to the average
       diverge             -> stays double_marking, awaits a senior
        |
   reconcile (senior, not A or B) -> final_mark set
        |
   when ALL tasks for the sitting are reconciled:
       Score.raw = objective_raw + Σ final_marks ; status -> final ; ScoreFinalized
```

## Properties (each verified by test)

- **Double marking.** Two *independent* human marks are required. If they agree within
  tolerance the task auto-reconciles to their average; otherwise a senior must step in.
- **Separation of duties.** A grader can't mark the same task twice, and a marker can't
  reconcile their own task — enforced in the service (not just the UI), like the
  question-review SoD.
- **AI is advisory.** `aiSuggest` stores a mark flagged `is_ai` and records it as the
  task's suggestion, but it never counts toward the two human marks and never finalizes —
  the AI-is-a-suggestion invariant (docs/01 §13). It's reached through the `AiGrader`
  contract, so the placeholder heuristic swaps for a real model with one binding change.
- **Score fold-in.** Only when every grading task for a sitting is reconciled does the
  manual total get added to the objective subtotal and the score flip to `final`
  (verified: objective 1 + manual 8 ⇒ raw 9; the divergent case ⇒ 1 + 6 = 7 after senior
  reconcile). The objective subtotal was preserved in `section_breakdown.objective_raw`
  at scoring time precisely so this fold-in is exact.

## API (under `/api/delivery/grading`, authenticated)

| Method & path | Permission | Purpose |
|---------------|-----------|---------|
| `GET /tasks` | `grading.read` | the pending / double-marking queue |
| `GET /tasks/{id}` | `grading.read` | question stem + candidate answer + marks so far |
| `POST /tasks/{id}/ai-suggest` | `grading.read` | advisory AI mark |
| `POST /tasks/{id}/marks` | `grading.mark` | submit a human mark |
| `POST /tasks/{id}/reconcile` | `grading.reconcile` | senior reconciliation |

Roles: a new `grader` system role holds `grading.mark` + `grading.read`; `exam_officer`
holds `grading.reconcile` + `grading.read` (the senior). A `student` is forbidden (403),
and a `grader` cannot reconcile (403) — both tested.

## Notes / future work

- Marks are absolute points per question; institutions configure consistent scales
  between objective rules and manual rubrics.
- The agreement tolerance (currently 1.0) and required-mark count (2) are constants;
  making them per-assessment policy is a small extension.
- The real AI grader (LLM-backed, rubric-aware) implements `AiGrader`; the current
  `HeuristicAiGrader` is a clearly-labelled stand-in.
