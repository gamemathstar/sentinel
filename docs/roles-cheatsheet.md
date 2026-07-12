# Legion CBT — Role Cheat‑Sheet

One‑page reference. Full detail: [19-users-roles-and-access.md](19-users-roles-and-access.md).

## Who's who

| Category | Role | In one line |
|---|---|---|
| **Admin** | Platform Super Admin | Runs the platform; bypasses every check (cross‑tenant). |
| **Admin** | Institution Admin | Owns everything within one institution. |
| **Exam ops** | Exam Officer | Builds & publishes exams, schedules, monitors, reconciles grading, reviews proctoring, certifies, reads analytics. |
| **Exam ops** | Proctor / Invigilator | Watches sessions, raises flags, reads rosters. (Final clear/uphold = Officer.) |
| **Exam ops** | Grader | Marks open‑ended responses (double‑marking). |
| **Authoring** | Question Author | Writes & imports questions, drafts versions. |
| **Authoring** | Question Reviewer | Reviews drafts (not their own). |
| **Authoring** | Question Moderator | Second reviewing pass. |
| **Authoring** | Question Approver | Final approval → item becomes assemble‑able. |
| **Candidate** | Student | Sits/resumes exams; reads own score, schedule, notifications. |

## Capability matrix (✅ = holds; Admins hold all)

| Capability | Officer | Proctor | Grader | Author | Reviewer/Mod | Approver | Student |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Author / edit questions | create | | | ✅ | | | |
| Review / approve questions | | | | | ✅ review | ✅ approve | |
| Import questions | ✅ | | | ✅ | | | |
| Create / share banks | ✅ | | | ✅ | | | |
| Build / publish assessments | ✅ | | | | | | |
| Manage blueprints & scoring | ✅ | | | | | | |
| Schedule (venues/sessions/map) | ✅ | read | | | | | read |
| Assign invigilators | ✅ | | | | | | |
| Assign candidates / monitor / add‑time | ✅ | | | | | | |
| Sit / resume an exam | | | | | | | ✅ |
| Mark open‑ended responses | | | ✅ | | | | |
| Reconcile double marks | ✅ | | | | | | |
| Proctoring — monitor / flag | ✅ | ✅ | | | | | |
| Proctoring — clear / uphold | ✅ | | | | | | |
| Analytics (read / compute) | ✅ | | | | | | |
| Certificates (issue/read/revoke) | ✅ | | | | | | |
| Reports & notifications | ✅ | | | | | | own |
| Read own score | ✅ | | | | | | ✅ |
| Manage users & roles | | | | | | | |

*Manage users & roles is Admin‑only. Verifying a certificate is public (no login).*

## Scope

A role can be **institution‑wide** (default) or **pinned to an org node** (faculty →
department → programme), granting the permission **only within that subtree**. Bank
visibility is separate (owner / org‑subtree / user‑share / group‑share / `manage_all`).

## Demo logins (`password`)

| Email | Role |
|---|---|
| admin@demo.legion.test | Institution Admin |
| officer@demo.legion.test | Exam Officer *(default login)* |
| author@demo.legion.test | Question Author |
| reviewer@demo.legion.test | Question Reviewer |
| moderator@demo.legion.test | Question Moderator |
| approver@demo.legion.test | Question Approver |
| grader1@ / grader2@demo.legion.test | Grader |
| proctor@demo.legion.test | Proctor |
| ada@ / bode@ / chidi@ / dela@demo.legion.test | Student |
