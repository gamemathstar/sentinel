# 19 — Users, Roles, Access & Feature Flows

This document is the single reference for **who can do what** in Legion CBT: every user
category, the permissions each role holds, and the end‑to‑end flow of each feature with
the exact gate that guards each step.

It is generated from the source of truth in code:

- Permission catalog & role matrix — `app/Modules/Identity/Support/Permissions.php`
- Gate enforcement — `app/Modules/Identity/Services/PermissionResolver.php`
- Per‑object policies — `app/Modules/*/Policies/*Policy.php`
- Routes — `routes/api.php`

---

## 1. How access control works

Four layers combine on every request:

1. **Authentication (bearer token).** `POST /api/auth/login` verifies the password and
   issues an opaque bearer token (only its hash is stored). If the account has MFA enabled,
   login returns an `mfa_required` challenge and the token is only issued after
   `POST /api/auth/mfa/verify`. Every non‑public route runs behind the `Authenticate`
   middleware, which also loads the user's **tenant context** (institution) for isolation.

2. **Tenant isolation.** Every tenant‑scoped model uses the `BelongsToTenant` global scope,
   so a user only ever sees rows for their own `institution_id`. Platform‑scope context
   bypasses this for system/super‑admin work only.

3. **Permissions (RBAC).** A user holds one or more **role assignments**. Each assignment
   maps to a system role → a set of permission **keys** (`context.aggregate.action`).
   `PermissionResolver::can($user, $key, $orgNodeId?)` answers every check.

   - An assignment with a **null scope** grants the permission **institution‑wide**.
   - An assignment **scoped to an org node** grants the permission **only within that node's
     subtree** (faculty → department → programme → …), matched by materialized path
     (ancestor‑or‑self). This is how you make someone "Grader for the Faculty of Science
     only" without giving them the whole tenant.

4. **Policies (per‑object gates).** Controllers call `$this->authorize(...)` or an explicit
   `ensure(PERMISSION)`. Policies add object‑level rules on top of the permission — e.g. a
   candidate may only `take` **their own** sitting; a reviewer may not approve **their own**
   authored version.

**Super admin short‑circuit.** `Gate::before` grants everything to a
`platform_super_admin`, before any policy runs.

**Reading the tables below:** ✅ = the role holds this permission directly. Institution Admin
and Platform Super Admin hold **every** permission (`*`).

---

## 2. Permission catalog

| Key | Meaning |
|---|---|
| `questionbank.item.create` | Author a new question |
| `questionbank.item.read` | View questions |
| `questionbank.item.update` | Edit / add a version to a question |
| `questionbank.item.review` | Review / moderate / approve / reject a version |
| `questionbank.item.import` | Bulk‑import questions (Legion/Aiken/GIFT) |
| `questionbank.bank.create` | Create a question bank |
| `questionbank.bank.share` | Share a bank with users / staff groups |
| `questionbank.bank.manage_all` | See/manage **every** bank, bypassing per‑bank visibility |
| `authoring.assessment.create` | Create an assessment |
| `authoring.assessment.read` | View assessments |
| `authoring.assessment.update` | Edit sections / pin questions / assemble |
| `authoring.assessment.publish` | Publish an assessment (freezes the paper) |
| `authoring.blueprint.manage` | Manage blueprints (difficulty/type mix) |
| `authoring.scoringrule.manage` | Manage scoring rules |
| `delivery.sitting.assign` | Assign candidates, monitor live, add time |
| `delivery.sitting.take` | Sit / resume an exam (candidate) |
| `scoring.score.read` | Read scores |
| `grading.mark` | Mark an open‑ended response (a marker) |
| `grading.reconcile` | Reconcile double marks into a final mark |
| `grading.read` | View the grading queue & AI suggestions |
| `analytics.read` | Read reliability & item statistics |
| `analytics.compute` | Recompute psychometrics from graded sittings |
| `proctoring.monitor` | Open sessions, ingest flags, compute risk |
| `proctoring.review` | Review flagged candidates, clear/uphold |
| `certification.issue` | Issue certificates |
| `certification.read` | List certificates |
| `certification.revoke` | Revoke a certificate |
| `reporting.generate` | Generate report artifacts |
| `reporting.read` | List/download reports |
| `notifications.send` | Dispatch notifications |
| `notifications.read` | Read own notifications |
| `scheduling.manage` | Create venues/sessions, map candidates, assign invigilators, release |
| `scheduling.read` | View selections, sessions, rosters |
| `iam.user.manage` | Manage users |
| `iam.role.manage` | Manage roles |

---

## 3. User categories & roles

There are **three broad categories** of user, split into ten system roles.

### A. Administrators (full control)

| Role | Scope | What they are |
|---|---|---|
| **Platform Super Admin** (`platform_super_admin`) | Cross‑tenant | Runs the platform. Bypasses **all** checks via `Gate::before`. |
| **Institution Admin** (`institution_admin`) | One tenant | Owns everything **within their institution** (`*`). |

### B. Exam operations staff

| Role | Holds | What they do |
|---|---|---|
| **Exam Officer** (`exam_officer`) | The operational super‑set (see matrix) | The day‑to‑day driver: builds & publishes assessments, schedules candidates, monitors live exams, reconciles grading, reviews proctoring, issues certificates, reads analytics, runs reports. |
| **Proctor / Invigilator** (`proctor`) | `proctoring.monitor`, `scheduling.read` | Watches sessions, raises/ingests flags, reads their session rosters. (Note: raising a final clear/uphold **decision** needs `proctoring.review`, which is an Exam Officer power.) |
| **Grader** (`grader`) | `grading.mark`, `grading.read` | Marks open‑ended responses in the double‑marking queue. |

### C. Question authoring staff (author → review → approve chain)

| Role | Holds | What they do |
|---|---|---|
| **Question Author** (`question_author`) | `item.create/read/update/import`, `bank.create/share` | Writes questions, imports banks, drafts new versions. |
| **Question Reviewer** (`question_reviewer`) | `item.read`, `item.review` | Reviews drafts (cannot approve their own authored version). |
| **Question Moderator** | same keys as reviewer (`question_reviewer`) | A second reviewing pass in the workflow. |
| **Question Approver** (`question_approver`) | `item.read`, `item.review` | Gives the final approval that makes an item assemble‑able. |

### D. Candidates

| Role | Holds | What they do |
|---|---|---|
| **Student / Candidate** (`student`) | `sitting.take`, `score.read`, `notifications.read`, `scheduling.read` | Sits/resumes exams, reads **their own** score and schedule and notifications. |

> **Staff vs. candidate.** A user is a **candidate** iff they hold the `student` role;
> everyone else is **staff**. The user list endpoint exposes `?role=staff` / `?role=candidate`
> so pickers (e.g. invigilator assignment) never show candidates.

---

## 4. Role × permission matrix

| Permission | Admin* | Exam Officer | Proctor | Grader | Author | Reviewer/Moderator | Approver | Student |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| item.create | ✅ | ✅ | | | ✅ | | | |
| item.read | ✅ | ✅ | | | ✅ | ✅ | ✅ | |
| item.update | ✅ | | | | ✅ | | | |
| item.review | ✅ | | | | | ✅ | ✅ | |
| item.import | ✅ | ✅ | | | ✅ | | | |
| bank.create | ✅ | ✅ | | | ✅ | | | |
| bank.share | ✅ | ✅ | | | ✅ | | | |
| bank.manage_all | ✅ | ✅ | | | | | | |
| assessment.create | ✅ | ✅ | | | | | | |
| assessment.read | ✅ | ✅ | | | | | | |
| assessment.update | ✅ | ✅ | | | | | | |
| assessment.publish | ✅ | ✅ | | | | | | |
| blueprint.manage | ✅ | ✅ | | | | | | |
| scoringrule.manage | ✅ | ✅ | | | | | | |
| sitting.assign | ✅ | ✅ | | | | | | |
| sitting.take | ✅ | | | | | | | ✅ |
| score.read | ✅ | ✅ | | | | | | ✅ |
| grading.mark | ✅ | | | ✅ | | | | |
| grading.reconcile | ✅ | ✅ | | | | | | |
| grading.read | ✅ | ✅ | | ✅ | | | | |
| analytics.read | ✅ | ✅ | | | | | | |
| analytics.compute | ✅ | ✅ | | | | | | |
| proctoring.monitor | ✅ | ✅ | ✅ | | | | | |
| proctoring.review | ✅ | ✅ | | | | | | |
| certification.issue | ✅ | ✅ | | | | | | |
| certification.read | ✅ | ✅ | | | | | | |
| certification.revoke | ✅ | ✅ | | | | | | |
| reporting.generate | ✅ | ✅ | | | | | | |
| reporting.read | ✅ | ✅ | | | | | | |
| notifications.send | ✅ | ✅ | | | | | | |
| notifications.read | ✅ | ✅ | | | | | | ✅ |
| scheduling.manage | ✅ | ✅ | | | | | | |
| scheduling.read | ✅ | ✅ | ✅ | | | | | ✅ |
| iam.user.manage | ✅ | | | | | | | |
| iam.role.manage | ✅ | | | | | | | |

\* Admin = Institution Admin and Platform Super Admin (both `*`).

---

## 5. Feature flows (with gates)

Each step lists the **endpoint** → **gate** that must pass.

### 5.1 Authentication & MFA — everyone

1. `POST /auth/login` *(public)* → password verified; if MFA on, returns a challenge.
2. `POST /auth/mfa/verify` *(public)* → TOTP verified → bearer token issued.
3. `GET /auth/me` → the caller's identity + resolved permissions.
4. `POST /auth/mfa/enroll` → `POST /auth/mfa/confirm` → turns on TOTP for the account.
5. `POST /auth/logout` → revokes the token.

### 5.2 Question banks & authoring chain — Author → Reviewer → Moderator → Approver

The point of the chain: **no question reaches an exam until independently approved.**

1. **Create a bank** — `POST /question-bank/banks` → policy `QuestionBankPolicy@create`
   (`bank.create`). Visibility is set here: `org_subtree` (visible within an org node's
   subtree), plus explicit **user** and **staff‑group** shares, or `manage_all`/owner.
2. **Share a bank** — `POST /question-bank/banks/{id}/share-user|share-group` →
   `QuestionBankPolicy@share` (`bank.share` **and** you can edit the bank).
3. **Author a question** — `POST /question-bank/items` → `ItemPolicy@create`
   (`item.create`). Every item belongs to exactly one bank and carries course /
   specialization (org node) / tags. Answer keys are written to the **split‑key vault**,
   never beside the question.
4. **Edit / new version** — `POST /question-bank/items/{id}/versions` → `ItemPolicy@update`
   (`item.update`).
5. **Import in bulk** — `POST /question-bank/items/import` → `ItemPolicy@import`
   (`item.import`).
6. **Review → moderate → approve** — `POST /question-bank/versions/{versionId}/reviews` →
   `ItemPolicy@review`. Gate = `item.review` **and** the reviewer is **not the author**
   (`author_id !== user.id`). The state machine advances
   `draft → reviewed → moderated → approved` (or `rejected` / `changes_requested`). Only an
   **approved** version is assemble‑able.

Who does which: **Author** does 1–5; **Reviewer** and **Moderator** do the middle passes of
6; **Approver** does the final pass of 6.

### 5.3 Assessment authoring — Exam Officer

1. **Blueprint** — `POST /authoring/blueprints` → `blueprint.manage`. Defines the target
   difficulty/type mix.
2. **Create assessment** — `POST /authoring/assessments` → `AssessmentPolicy@create`
   (`assessment.create`). Pins a scoring rule.
3. **Add section** — `POST /authoring/assessments/{id}/sections` → `assessment.update`.
4. **Select questions** — `POST /authoring/assessments/{id}/sections/{sectionId}/items`
   (pin specific approved versions, filtered by course/specialization/tag) → `assessment.update`.
   *or* **Auto‑assemble** — `POST .../sections/{sectionId}/assemble` (PaperAssembler draws by
   the blueprint's difficulty apportionment, restricted to banks the user can see).
5. **Publish** — `POST /authoring/assessments/{id}/publish` → `AssessmentPolicy@publish`
   (`assessment.publish`). Freezes the paper (sections reference pinned `item_version_id`s).

### 5.4 Scheduling & timetabling — Exam Officer (`scheduling.manage`)

All under `/scheduling`; read actions need `scheduling.read`, writes need `scheduling.manage`.

1. **Preview a selection** — `POST /scheduling/selection/preview` → `scheduling.read`.
   A selection is `all` students, or any mix of **faculty / department / programme** org
   nodes + optional **level** filter. Resolved via subtree path‑coverage against
   `student_enrollments`, grouped by programme + level.
2. **Venues** — `POST /scheduling/venues` (`manage`), `GET /scheduling/venues` (`read`).
3. **Auto‑map** — `POST /scheduling/assessments/{id}/auto-map` → `scheduling.manage`.
   Give a selection + venues (with capacity) + start‑times + duration; the mapper creates one
   **session per (venue × start‑time)** and distributes cohort‑ordered candidates up to each
   slot's capacity. Many sessions per assessment (e.g. 09:00 Hall A **and** 09:00 Lab B).
4. **Manual session** — `POST /scheduling/assessments/{id}/sessions` (`manage`), then
   `POST /scheduling/sessions/{sessionId}/candidates` to seat candidates (capacity‑checked).
5. **Invigilators** — `POST /scheduling/sessions/{sessionId}/invigilators` → `manage`.
   The picker is **staff‑only** (candidates excluded). Assignable now or later.
6. **Roster** — `GET /scheduling/assessments/{id}/roster` → `read`.
7. **Release** — `POST /scheduling/assessments/{id}/release` → `manage`. Converts scheduled
   records into live **Delivery sittings** (the assessment must be published/live).

### 5.5 Sitting the exam — Student (`sitting.take`, own sitting only)

1. **Assigned** — created by release (5.4) or `POST /delivery/assessments/{id}/sittings`
   (`SittingPolicy@assign`, staff).
2. **Start** — `POST /delivery/sittings/{id}/start` → `SittingPolicy@take` (must be **your**
   sitting). Sets the server‑authoritative deadline.
3. **Fetch paper** — `GET /delivery/sittings/{id}` → `take`. Questions in display order,
   **never** answer keys.
4. **Answer** — `POST /delivery/sittings/{id}/responses` → `take`. Append‑only; correcting an
   answer writes a new response, nothing is lost.
5. **Resume after a disconnect** — `POST /delivery/sittings/{id}/resume` → `take`. Returns the
   paper, **saved answers**, and remaining server time — the student picks up exactly where
   they left off (including time).
6. **Submit** — `POST /delivery/sittings/{id}/submit` → `take`. Scoring runs (JIT vault
   scoring); open‑ended items spawn grading tasks.
7. **See score** — `GET /delivery/sittings/{id}/score` → `SittingPolicy@viewScore`
   (`score.read` **and** it's your sitting, or you hold `sitting.assign`).

### 5.6 Live monitoring — Exam Officer / staff (`sitting.assign`)

1. **Board** — `GET /delivery/assessments/{id}/monitor` → `authorize('assign')`. Every sitting
   with progress (answered/total), remaining time, and proctoring risk.
2. **Candidate detail** — `GET /delivery/sittings/{id}/detail` → `authorize('assign')`.
   Progress + flag timeline + explainable risk.
3. **Add time** — `POST /delivery/sittings/{id}/extend` → `authorize('assign')`. Extends the
   server deadline (accommodation or outage compensation); reopens a lapsed deadline.

### 5.7 Proctoring — Proctor monitors, Officer reviews

Ingest side (`proctoring.monitor`):
1. `POST /proctoring/sittings/{sittingId}/session` — open a session.
2. `POST /proctoring/sessions/{sessionId}/flags` — record a flag/signal.
3. `POST /proctoring/sessions/{sessionId}/assess` — recompute the explainable (noisy‑OR) risk.

Review side (`proctoring.review`):
4. `GET /proctoring/review-queue` — flagged candidates above threshold.
5. `POST /proctoring/risk/{riskId}/review` — **clear** or **uphold**. A high score routes to
   human review; the system **never auto‑voids** a sitting.

The live monitor's risk badge deep‑links to
`/assessments/{id}/proctoring?focus={riskId}` to preselect the flagged candidate.

### 5.8 Grading (open‑ended) — Grader marks, Officer reconciles

1. **Queue** — `GET /delivery/grading/tasks` → `grading.read`.
2. **Task detail** — `GET /delivery/grading/tasks/{id}` → `grading.read`.
3. **AI suggestion** — `POST /delivery/grading/tasks/{id}/ai-suggest` → `grading.read`.
   AI is just another marker (`is_ai=true`) — **advisory** until a human reconciles.
4. **Mark** — `POST /delivery/grading/tasks/{id}/marks` → `grading.mark` (Grader). Two
   independent human marks are collected (double‑marking).
5. **Reconcile** — `POST /delivery/grading/tasks/{id}/reconcile` → `grading.reconcile`
   (Exam Officer / senior). Produces the final mark; a score always records the scoring‑rule
   version that produced it.

### 5.9 Analytics & psychometrics — Exam Officer

1. **Compile** — `POST /analytics/assessments/{id}/compile` → `analytics.compute`. Recomputes
   KR‑20, Cronbach α, SEM, and per‑item facility/discrimination/distractors from **graded**
   sittings; writes measured difficulty back into the item bank.
2. **Reliability** — `GET /analytics/assessments/{id}/reliability` → `analytics.read`.
3. **Item analysis** — `GET /analytics/assessments/{id}/items` → `analytics.read`.
4. **Single item** — `GET /analytics/items/{itemId}/statistics` → `analytics.read`.

### 5.10 Certification — Exam Officer

1. **Issue** — `POST /certification/sittings/{sittingId}/issue` → `certification.issue`.
   (Also issued automatically when a score is finalized.)
2. **List** — `GET /certification/certificates` → `certification.read`.
3. **Revoke** — `POST /certification/certificates/{id}/revoke` → `certification.revoke`.
4. **Verify** — `GET /certification/verify/{token}` → **public**. The token *is* the
   credential; no auth required.

### 5.11 Reporting & Notifications — Exam Officer

- `POST /reporting/reports` (`reporting.generate`), `GET /reporting/reports`,
  `GET /reporting/reports/{id}`, `GET /reporting/reports/{id}/download` (`reporting.read`).
- `POST /notifications` (`notifications.send`), `GET /notifications` (`notifications.read` —
  recipients read their own, idempotent send).

### 5.12 Administration — Institution Admin

- `GET /iam/users` (+ `?role=staff|candidate`) — the user directory (share & invigilator
  pickers). Managing users/roles is `iam.user.manage` / `iam.role.manage`, held by Admins.
- `GET /tenancy/org-nodes` — the faculty→department→programme hierarchy used by bank
  scoping, item course/specialization, and scheduling selections.

---

## 6. Scoped roles in practice

Because a role assignment can be pinned to an org node, the same role means different reach:

- **Grader (institution‑wide):** marks any script in the tenant.
- **Grader scoped to "Faculty of Science":** `grading.mark` only passes for resources whose
  org‑node path is ancestor‑or‑self of that faculty. Outside it, `can()` returns false.
- **Question Reviewer scoped to a department:** reviews only that department's items.

Bank visibility is a **separate** axis (`BankVisibilityResolver`): even with `item.read`, a
user only sees banks that are shared with them, cover their org subtree, are owned by them, or
if they hold `bank.manage_all`.

---

## 7. Demo accounts

`php artisan migrate:fresh --seed` seeds one institution ("Demo University"). All passwords are
`password`.

| Email | Role |
|---|---|
| `admin@demo.legion.test` | Institution Admin |
| `officer@demo.legion.test` | Exam Officer |
| `author@demo.legion.test` | Question Author |
| `reviewer@demo.legion.test` | Question Reviewer |
| `moderator@demo.legion.test` | Question Moderator |
| `approver@demo.legion.test` | Question Approver |
| `grader1@demo.legion.test`, `grader2@demo.legion.test` | Grader |
| `proctor@demo.legion.test` | Proctor |
| `ada@ / bode@ / chidi@ / dela@demo.legion.test` | Student (candidate) |

The default operational login is **`officer@demo.legion.test`** — an Exam Officer, which can
reach the whole build/schedule/monitor/grade/analytics/certify pipeline.
