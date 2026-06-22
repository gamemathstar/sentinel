# 07 — Question Bank Module

The first vertical module, built on the schema and tenancy foundation. It is the
"heart of the platform" (spec) and exercises the most foundational machinery: tenant
scoping, item versioning, the moderation workflow with separation of duties, the import
engine, and — most importantly — the **answer-key separation** from the security model.

All behavior here is covered by **21 passing tests** (`php artisan test`).

## Code layout

```
app/Support/Tenancy/
  TenantContext.php        per-request tenant + acting user (singleton)
  HasUuidv7.php            UUIDv7 string primary keys
  BelongsToTenant.php      global scope + institution_id stamping

app/Modules/Tenancy/Models/      Institution, OrgNode
app/Modules/Identity/Models/     User
app/Modules/QuestionBank/
  Models/                  Item, ItemVersion, Stimulus, ItemReview
  Services/
    AnswerKeyVault.php     splits the correct answer into the vault schema
    ItemService.php        create item/version, content hashing, answer routing
    ReviewService.php      approval pipeline + separation of duties
  Import/
    QuestionFormatParser.php (interface)
    Parsers/{LegionFormatParser,AikenParser,GiftParser}.php
    DuplicateDetector.php
    ImportManager.php
  Policies/ItemPolicy.php
  Http/{Controllers,Requests}/
  Exceptions/WorkflowViolation.php

app/Http/Middleware/SetTenantContext.php
routes/api.php
tests/Feature/QuestionBank/*
```

## The defining behavior: answer-key separation (verified)

When you create an item, the input is split:

- `content` (stem + option texts) → `item_versions.content`
- `answer` (the scoring truth) → `AnswerKeyVault` → `vault.answer_keys`

The vault row is keyed by `version_token = HMAC(K_map, item_version_id)` — an opaque
token, not the version id — and the payload is encrypted. Tests prove that:

- the question content never contains a correctness marker (checked down to the raw JSON
  column);
- the vault row is keyed by the derived token, and **no** row is keyed by the plain id;
- `ItemService` rejects any attempt to smuggle a `correct`/`answer` key into `content`;
- the answer round-trips and scores correctly **only** through the vault.

> Crypto note: the vault currently uses Laravel's app-key encryption as a working
> stand-in for the production split-key DEK (KMS + HSM, Shamir). The *separation and
> opaque-token* properties are fully real now; key-custody hardening is a later phase
> (docs/04 §11).

## API

All routes are under `/api/question-bank` and run behind `SetTenantContext`.

| Method & path | Purpose |
|---------------|---------|
| `GET /items` | list items (tenant-scoped, paginated; filter `?type=`, `?status=`) |
| `POST /items` | create an item + first version |
| `GET /items/{id}` | show item with versions |
| `POST /items/{id}/versions` | add a new version (editing = new version) |
| `POST /versions/{id}/reviews` | submit a review decision (workflow + SoD) |
| `POST /items/import` | bulk import (`format`: `legion`/`aiken`/`gift`) |

Tenant context is supplied via headers **as a stub until the IAM module ships auth**:
`X-Institution-Id`, `X-User-Id` (or `X-Platform-Scope: 1`). Example:

```bash
curl -X POST localhost:8000/api/question-bank/items \
  -H 'X-Institution-Id: <uuid>' -H 'X-User-Id: <uuid>' \
  -H 'Content-Type: application/json' \
  -d '{"type":"single","content":{"stem":"2+2?","options":{"a":"3","b":"4"}},"answer":{"correct":["b"]}}'
```

## Import formats

- **Legion (native):** `?? stem {Difficulty}` / `** option` / trailing `==` marks correct.
- **Aiken:** stem, `A. … B. …`, `ANSWER: B`.
- **GIFT (Moodle):** `stem {=correct ~wrong}`, `{T}`/`{F}`, all-`=` ⇒ short answer.

Import is **partial-success**: a malformed question becomes a per-row error
(`{created, duplicates, errors, results[]}`) without aborting the batch. **Duplicate
detection** runs within the batch and against the existing bank via the version
`content_hash`, scoped to the tenant.

## Separation of duties (verified)

The approval pipeline is `draft → reviewed → moderated → approved`. Enforced as
invariants in `ReviewService` (not just UI): the author can never advance their own
version, and no subject may perform two consecutive approval stages (true 4-eyes).
Reaching `approved` flips the item to `active`.

## Running the tests

```bash
createdb legion_cbt_test    # one-time
php artisan test            # 23 passing (21 module + 2 skeleton)
```

Tests run against a real Postgres test database (the schema needs jsonb/partitioning/
vault/RLS), wrapped in transactions via `RefreshDatabase`.
