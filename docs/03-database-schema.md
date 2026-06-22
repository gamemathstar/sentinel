# 03 — Database Schema & Entity-Relationship Diagrams

This is the data model. It is **executable**: every table here exists as a Laravel
migration under [`database/migrations/`](../database/migrations/). The diagrams below
group tables by bounded context (doc 01); the security-critical separation of items
from answer keys is realized physically here.

## Conventions

- **Primary keys are UUIDv7** (`uuid`), not auto-increment integers — so ids are
  non-guessable, globally unique across shards, and time-ordered for index locality.
- **Every tenant-scoped table carries `institution_id`** and is filtered by a global
  Eloquent scope. The `platform`-level tables (system roles, platform admins) do not.
- **Timestamps** `created_at`, `updated_at` everywhere; soft deletes (`deleted_at`)
  only where retention requires it (items, assessments) — *never* on responses, scores,
  or audit (those are append-only / immutable).
- **Money/score values** use `numeric`, never float, to keep scoring reproducible.
- **JSONB** holds typed-but-variable payloads (item content per type, blueprint rules,
  scoring formulas) so adding a question type is data, not a migration.
- **Enums** are PostgreSQL `text` + `check` constraints (portable, alterable) rather
  than native enum types.

---

## 1. Tenancy & Identity (shared kernel)

```mermaid
erDiagram
    institutions ||--o{ org_nodes : contains
    institutions ||--o{ users : "scopes"
    org_nodes ||--o{ org_nodes : "parent_of"
    users ||--o{ role_assignments : has
    roles ||--o{ role_assignments : grants
    roles ||--o{ permission_role : includes
    permissions ||--o{ permission_role : in
    users ||--o{ mfa_factors : owns
    users ||--o{ sessions : opens

    institutions {
        uuid id PK
        string name
        string slug UK
        string status
        uuid encryption_key_ref "KMS key id for field encryption"
        jsonb settings
    }
    org_nodes {
        uuid id PK
        uuid institution_id FK
        uuid parent_id FK "nullable"
        string type "faculty|department|programme|course|topic|learning_outcome"
        string name
        string code
        int depth
        string path "materialized path for subtree queries"
    }
    users {
        uuid id PK
        uuid institution_id FK "null for platform admins"
        string email UK
        string password_hash
        string status "active|suspended|locked"
        boolean mfa_enabled
    }
    roles {
        uuid id PK
        uuid institution_id FK "null = system role"
        string name
        boolean is_system
    }
    permissions {
        uuid id PK
        string key UK "questionbank.item.create"
        string description
    }
    permission_role {
        uuid role_id FK
        uuid permission_id FK
    }
    role_assignments {
        uuid id PK
        uuid user_id FK
        uuid role_id FK
        uuid scope_org_node_id FK "nullable; null = institution-wide"
        uuid institution_id FK
    }
    mfa_factors {
        uuid id PK
        uuid user_id FK
        string type "totp|webauthn|sms"
        string secret_enc "encrypted"
    }
    sessions {
        uuid id PK
        uuid user_id FK
        string ip
        string user_agent
        timestamp last_active
    }
```

**Why `role_assignments` is separate from `users`:** a person is a Lecturer in
Physics *and* an Exam Officer in the Faculty of Science. Roles are scoped to an
org node, and effective permissions are the union over a subject's assignments whose
scope is an ancestor-or-self of the resource's org node. Custom roles = a tenant row
in `roles` with `is_system=false`.

---

## 2. Question Bank — **note the answer-key separation**

```mermaid
erDiagram
    items ||--o{ item_versions : "has revisions"
    items }o--o{ org_nodes : "tagged via item_org_node"
    stimuli ||--o{ item_versions : "referenced by"
    item_versions ||--o{ item_reviews : "moderated by"
    item_versions ||--o| answer_keys : "scored by (SEPARATE store)"

    items {
        uuid id PK
        uuid institution_id FK
        string type "single|multiple|matching|ordering|hotspot|essay|code|sql|numerical|..."
        uuid current_version_id FK
        string status "draft|active|retired"
        numeric difficulty "0..1 facility index, updated by analytics"
        numeric discrimination "point-biserial, updated by analytics"
        smallint bloom_level "1..6"
        int expected_seconds
    }
    item_versions {
        uuid id PK
        uuid item_id FK
        int version_no
        uuid stimulus_id FK "nullable shared passage/media"
        jsonb content "stem + options/blanks/pairs WITHOUT correctness flags"
        string author_id FK
        string state "draft|reviewed|moderated|approved|retired"
        string content_hash "for duplicate detection"
    }
    answer_keys {
        uuid id PK "lives in separate schema 'vault'"
        uuid version_token UK "opaque; NOT item_version_id"
        bytea key_blob_enc "encrypted scoring truth"
        string algo "scoring algorithm id"
    }
    stimuli {
        uuid id PK
        uuid institution_id FK
        string kind "passage|image|audio|video|casestudy"
        string s3_key
        jsonb meta
    }
    item_reviews {
        uuid id PK
        uuid item_version_id FK
        uuid reviewer_id FK
        string decision "approve|reject|revise"
        text comment
    }
    item_org_node {
        uuid item_id FK
        uuid org_node_id FK
    }
```

The **critical structural fact**: `item_versions.content` holds the stem and the option
*texts* but **no flag marking which option is correct**. The correct answer lives in
`answer_keys` (a separate `vault` schema / separate DB), keyed by an **opaque
`version_token`**, not by `item_version_id`. The mapping from a version to its token is
itself held encrypted. A read of the entire question-bank schema yields questions
without answers. Mechanism detail: [`04-security-architecture.md`](04-security-architecture.md).

---

## 3. Assessment Authoring

```mermaid
erDiagram
    assessments ||--o{ assessment_sections : "composed of"
    assessments }o--|| blueprints : "uses"
    assessments }o--|| scoring_rules : "scored by"
    assessments }o--o| proctoring_policies : "monitored by"
    assessment_sections }o--o{ item_versions : "draws (pinned) via section_item"

    assessments {
        uuid id PK
        uuid institution_id FK
        uuid org_node_id FK "course/programme it belongs to"
        string title
        string kind "practice|ca|midterm|final|postutme|recruitment|certification|mock"
        string status "draft|published|live|closed|archived"
        timestamp window_opens_at
        timestamp window_closes_at
        int duration_seconds
        boolean is_adaptive
        uuid blueprint_id FK
        uuid scoring_rule_id FK
        uuid proctoring_policy_id FK
    }
    assessment_sections {
        uuid id PK
        uuid assessment_id FK
        string title
        int position
        jsonb selection "fixed item set OR blueprint-driven random draw spec"
        uuid scoring_rule_id FK "nullable section override"
    }
    blueprints {
        uuid id PK
        uuid institution_id FK
        string name
        jsonb constraints "e.g. {difficulty:{easy:0.4,med:0.4,hard:0.2}, topics:{...}, bloom:{...}}"
    }
    scoring_rules {
        uuid id PK
        uuid institution_id FK
        string name
        int version
        jsonb policy "correct/wrong/blank weights, partial, negative, confidence, formula"
    }
    proctoring_policies {
        uuid id PK
        uuid institution_id FK
        string name
        jsonb signals "which detectors enabled + thresholds"
        boolean lockdown_required
    }
    section_item {
        uuid section_id FK
        uuid item_version_id FK "PINNED version for reproducibility"
        int position
    }
```

Sections reference **`item_version_id`** (pinned), never `item_id`. Editing an item in
the bank afterward creates a new version and does not alter a published paper — the
exam is reproducible forever.

---

## 4. Exam Delivery — the hot path

```mermaid
erDiagram
    assessments ||--o{ sittings : "attempted as"
    users ||--o{ sittings : "by candidate"
    sittings ||--o{ responses : "records (append-only)"
    sittings ||--|| variant_manifests : "assigned"

    sittings {
        uuid id PK
        uuid institution_id FK
        uuid assessment_id FK
        uuid candidate_id FK
        string status "assigned|in_progress|submitted|graded|voided"
        timestamp started_at
        timestamp submitted_at
        int server_deadline_epoch "authoritative"
        string variant_token "points to manifest"
        jsonb sync_meta "offline checkpoint/sequence"
    }
    responses {
        uuid id PK
        uuid sitting_id FK
        uuid item_version_id FK
        int sequence "monotonic per sitting; append-only"
        jsonb answer "candidate selection/text/etc"
        numeric confidence "nullable, for confidence-based scoring"
        int time_spent_ms
        timestamp answered_at
    }
    variant_manifests {
        uuid id PK
        uuid sitting_id FK
        jsonb manifest "ordered item_version_ids + option order + numeric seeds"
        string s3_key "large manifests offloaded"
    }
```

`responses` is **PARTITIONED BY RANGE on `answered_at`** (monthly) and is **append-only**
— a correction is a new row with a higher `sequence`, the latest sequence per
`(sitting_id, item_version_id)` wins. This makes offline conflict resolution trivial and
keeps an immutable answer trail for disputes.

---

## 5. Scoring & Grading

```mermaid
erDiagram
    sittings ||--|| scores : "produces"
    sittings ||--o{ grading_tasks : "open-ended items need"
    scoring_rules ||--o{ scores : "computed under (versioned)"
    grading_tasks ||--o{ grading_marks : "marked by graders"

    scores {
        uuid id PK
        uuid sitting_id FK
        uuid scoring_rule_id FK
        int scoring_rule_version
        numeric raw_score
        numeric scaled_score
        jsonb section_breakdown
        jsonb competency_breakdown
        string status "provisional|final|under_review"
    }
    grading_tasks {
        uuid id PK
        uuid sitting_id FK
        uuid response_id FK
        string type "essay|short_answer|code"
        string status "pending|in_progress|double_marking|reconciled"
        uuid ai_suggestion_id FK "nullable AI pre-mark"
    }
    grading_marks {
        uuid id PK
        uuid grading_task_id FK
        uuid grader_id FK
        numeric mark
        text rubric_breakdown
        boolean is_ai
    }
```

A `score` always records **which scoring-rule version** produced it, so a result is
reproducible from `(responses, scoring_rule@version)`. Double-marking with reconciliation
is modelled as multiple `grading_marks` per task; AI is just another marker flagged
`is_ai=true` whose mark is advisory until a human reconciles.

---

## 6. Proctoring

```mermaid
erDiagram
    sittings ||--|| proctoring_sessions : "monitored by"
    proctoring_sessions ||--o{ proctoring_flags : "raises"
    proctoring_sessions ||--o{ evidence_clips : "captures"
    proctoring_sessions ||--|| risk_assessments : "summarized as"

    proctoring_sessions {
        uuid id PK
        uuid sitting_id FK
        uuid institution_id FK
        string mode "live|record_review|ai_only|none"
        boolean lockdown_active
        jsonb identity_verification "face/voice/id match results"
    }
    proctoring_flags {
        uuid id PK
        uuid proctoring_session_id FK
        string type "multiple_faces|face_absent|phone|talking|tab_switch|vm_detected|..."
        numeric confidence
        timestamp occurred_at
        uuid evidence_clip_id FK "nullable"
        string source "client|edge|server_inference"
    }
    evidence_clips {
        uuid id PK
        uuid proctoring_session_id FK
        string s3_key "encrypted media"
        string kind "video|audio|screenshot|screen"
        timestamp from_ts
        timestamp to_ts
    }
    risk_assessments {
        uuid id PK
        uuid proctoring_session_id FK
        numeric cheating_probability "0..1"
        numeric suspicion_score
        jsonb timeline "explainable contributing flags"
        string status "auto|reviewed|cleared|upheld"
    }
```

Every `risk_assessment` is **explainable**: its `timeline` references the specific
`flags` (and their evidence) that contributed, so a human reviewer and an appeals
process can audit *why* a candidate was scored risky. No opaque "AI said cheater."

---

## 7. Audit, Certification, Notifications, Analytics (summary)

```mermaid
erDiagram
    audit_entries {
        uuid id PK
        uuid institution_id FK "nullable for platform"
        uuid actor_id FK
        string action "item.create|exam.access|score.publish|..."
        string subject_type
        uuid subject_id
        jsonb metadata
        string prev_hash "hash chain"
        string entry_hash "= H(prev_hash + payload)"
        timestamp occurred_at
    }
    certificates {
        uuid id PK
        uuid institution_id FK
        uuid candidate_id FK
        uuid assessment_id FK
        string serial UK
        string verification_token UK
        string anchor_txid "nullable blockchain anchor"
        string s3_key "rendered PDF/badge"
        timestamp issued_at
    }
    item_statistics {
        uuid id PK
        uuid item_id FK
        int sample_n
        numeric facility_index
        numeric discrimination_index
        jsonb distractor_analysis
        jsonb irt_params "a,b,c"
    }
    assessment_reliability {
        uuid id PK
        uuid assessment_id FK
        numeric kr20
        numeric cronbach_alpha
        numeric sem "standard error of measurement"
    }
    notifications {
        uuid id PK
        uuid institution_id FK
        uuid recipient_id FK
        string channel "email|sms|push|whatsapp"
        string event_key
        string status "queued|sent|failed"
        string dedupe_key UK "idempotency"
    }
```

- **`audit_entries` is hash-chained and append-only**: `entry_hash = H(prev_hash ‖
  canonical(payload))`. Tampering with any historical row breaks every subsequent hash,
  making silent edits detectable. Enforced further by DB-level `REVOKE UPDATE, DELETE`
  on the table for the application role (security doc §audit).
- **`certificates.verification_token`** lets a third party verify authenticity via a
  public portal without access to the institution's live data; the optional
  `anchor_txid` records a blockchain anchor of the cert hash for trustless verification.
- **`item_statistics` / `assessment_reliability`** are read models recomputed by
  analytics workers from finalized scores — never written on the transactional path.

---

## 8. Indexing & partitioning summary

| Table | Key indexes | Partitioning |
|-------|-------------|--------------|
| `responses` | `(sitting_id, item_version_id, sequence desc)`, `(answered_at)` | RANGE on `answered_at` (monthly) |
| `sittings` | `(assessment_id, status)`, `(candidate_id)`, `(institution_id)` | HASH on `institution_id` at T3 |
| `items` | `(institution_id, type, status)`, GIN on `content` | — |
| `item_versions` | `(item_id, version_no)`, `(content_hash)` for dedupe | — |
| `audit_entries` | `(institution_id, occurred_at)`, `(subject_type, subject_id)` | RANGE on `occurred_at` |
| `proctoring_flags` | `(proctoring_session_id, occurred_at)`, `(type)` | RANGE on `occurred_at` at T3 |

---

## 9. Tenant isolation enforcement (defense in depth)

1. **Application layer:** a global Eloquent scope adds `where institution_id = ?` to
   every tenant-scoped model automatically; bypassing it requires an explicit,
   audited call.
2. **Database layer:** PostgreSQL **Row-Level Security** policies on tenant tables keyed
   to a `SET app.current_institution` session variable — so even raw SQL from the app
   role cannot cross tenants.
3. **Crypto layer:** field-level encryption uses a **per-institution key**, so leaked
   ciphertext from one tenant is undecryptable with another tenant's key.

These three layers mean tenant isolation does not depend on any single developer
remembering to add a `where` clause.
