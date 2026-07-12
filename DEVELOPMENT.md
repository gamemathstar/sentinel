# Legion CBT — Developer Guide

Everything you need to get the system running locally and continue development. If you only
read one file before touching the code, read this one — then dip into `docs/` for depth.

Legion CBT is a **multi-tenant examination platform** (question banking → authoring →
scheduling → delivery → proctoring → grading → analytics → certification). It's a **monorepo**:

- **Backend** — Laravel 12 (PHP 8.3+), PostgreSQL, organised as a **modular monolith**.
- **Frontend** — Next.js 16 / React 19 / Tailwind v4, in `apps/web/`.

---

## 1. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3+** | `php -v` |
| Composer | 2.x | |
| Node.js | **20+** | `node -v` |
| npm | 10+ | ships with Node |
| PostgreSQL | **16** (14+ works) | the app uses schemas + row‑level security, so **not** sqlite |
| Redis | 7 *(optional in dev)* | only needed if you run the queue worker; you can set `QUEUE_CONNECTION=sync` instead |

macOS quickstart: `brew install php composer node postgresql@16 redis` and
`brew services start postgresql@16`.

---

## 2. First‑time setup

### 2.1 Backend

```bash
# from the repo root
composer install
cp .env.example .env
php artisan key:generate
```

Create the database and a user (names must match your `.env`):

```bash
# using psql (adjust the role/password to taste)
createdb legion_cbt
psql -c "CREATE ROLE legion LOGIN PASSWORD 'secret';"
psql -c "GRANT ALL PRIVILEGES ON DATABASE legion_cbt TO legion;"
```

Point `.env` at it, then migrate + seed the demo institution:

```dotenv
# .env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=legion_cbt
DB_USERNAME=legion
DB_PASSWORD=secret
QUEUE_CONNECTION=sync   # set to `redis` only if you run a Redis + queue worker
```

```bash
php artisan migrate --seed
```

`--seed` runs `DemoSeeder`, which drives the **real** domain services (not raw inserts) to
produce one institution with users, banks, an assessment, sittings, proctoring, grading,
analytics and certificates. See §6 for the demo logins.

### 2.2 Frontend

```bash
cd apps/web
npm install
```

The client reads the API base URL from `NEXT_PUBLIC_API_URL` (defaults to
`http://localhost:8000`). `apps/web/.env.local` already sets this; create it if missing:

```dotenv
# apps/web/.env.local
NEXT_PUBLIC_API_URL=http://localhost:8000
```

> The UI works **signed‑out** too: every screen falls back to demo data when the API is
> unreachable or you're not authenticated (`useApiList` / per‑component try‑catch). Sign in
> to see live data.

---

## 3. Running it

Two servers: the Laravel API (port **8000**) and the Next.js app (port **3000**).

**Option A — one command (recommended).** From the repo root:

```bash
composer dev
```

This runs the API server, the queue worker, log tailing (`pail`), and `npm run dev`
concurrently. Open **http://localhost:3000**.

**Option B — separate terminals.**

```bash
# terminal 1 — API
php artisan serve                # http://localhost:8000

# terminal 2 — frontend
cd apps/web && npm run dev        # http://localhost:3000

# terminal 3 (only if QUEUE_CONNECTION=redis)
php artisan queue:listen --tries=1
```

---

## 4. Testing

Tests run against a **real Postgres test database** (`RefreshDatabase` + schemas + RLS), so
you must create it once:

```bash
createdb legion_cbt_test
```

> ⚠️ **`phpunit.xml` currently hardcodes `DB_USERNAME=user77` with an empty password**
> (a machine‑specific default). Edit the `DB_USERNAME` / `DB_PASSWORD` env entries in
> `phpunit.xml` to your Postgres credentials, or grant your local role access to
> `legion_cbt_test`. This is the #1 thing that trips up a fresh checkout.

Run the suite:

```bash
php artisan test                       # full suite (~140 tests)
php artisan test --filter=Scheduling   # one group
composer test                          # clears config, then runs the suite
```

**Formatting** — this project uses **Laravel Pint**. Run it on changed files before committing:

```bash
./vendor/bin/pint app/Modules/Scheduling   # a path
./vendor/bin/pint                          # everything
```

Note: tests are **PHPUnit**, not Pest. Frontend has no test runner yet; `npm run build`
(in `apps/web`) is the compile/type check gate.

---

## 5. Project structure

```
legion_cbt/
├── app/
│   ├── Modules/<Context>/        ← the modular monolith (see below)
│   ├── Providers/AppServiceProvider.php   ← policies, event wiring, route id patterns, DI bindings
│   └── Support/Tenancy/          ← BelongsToTenant, HasUuidv7, TenantContext
├── routes/api.php                ← every HTTP endpoint (grouped by module)
├── database/
│   ├── migrations/               ← schema (timestamp‑ordered)
│   └── seeders/DemoSeeder.php     ← the full‑lifecycle demo seed
├── tests/Feature/<Context>/      ← PHPUnit feature tests (real Postgres)
├── apps/web/                     ← Next.js frontend
│   └── src/
│       ├── app/(app)/…           ← authenticated screens (route = folder)
│       ├── app/exam/[id]/        ← the candidate exam runtime
│       ├── components/           ← shared UI + feature components
│       └── lib/{api.ts,demo.ts,useApiList.ts,cn.ts}
├── docs/                         ← architecture + per‑module docs (01–19)
└── DEVELOPMENT.md                ← you are here
```

### The modular monolith

Each bounded context lives under `app/Modules/<Context>/` with a consistent shape:

```
app/Modules/Scheduling/
├── Models/            Eloquent models (use BelongsToTenant for tenant scoping)
├── Services/          business logic (controllers stay thin)
├── Http/Controllers/  REST surface
├── Policies/          per‑object authorization (optional)
└── Exceptions/        domain errors (mapped to 4xx in controllers)
```

The contexts: **Tenancy, Identity** (auth + RBAC), **QuestionBank, Authoring, Scheduling,
Delivery** (sittings/scoring/grading), **Proctoring, Analytics, Certification, Reporting,
Notifications**.

### Cross‑cutting mechanics you must know

- **Tenant isolation** — models use the `BelongsToTenant` trait: a global scope filters to the
  current `institution_id` and stamps it on create. The tenant is set from the authenticated
  user by the `Authenticate` middleware (`TenantContext` singleton).
- **UUIDs** — primary keys are UUIDv7 via `HasUuidv7`. Route id params are constrained to the
  UUID shape in `AppServiceProvider`, so a bad id 404s instead of hitting Postgres.
- **RBAC** — `PermissionResolver::can($user, $key, $orgNodeId?)`. Roles → permission keys live
  in `app/Modules/Identity/Support/Permissions.php`. Assignments can be institution‑wide or
  scoped to an org node (subtree via materialized path). See `docs/19-users-roles-and-access.md`.
- **Answer‑key vault** — correct answers are HMAC‑tokenised + encrypted (`AnswerKeyVault`),
  never stored beside the question. Delivery scores just‑in‑time against the vault.

---

## 6. Demo accounts

After `migrate --seed` (institution "Demo University"), all passwords are `password`:

| Email | Role |
|---|---|
| `admin@demo.legion.test` | Institution Admin |
| `officer@demo.legion.test` | **Exam Officer** (use this — reaches the whole pipeline) |
| `author@ / reviewer@ / moderator@ / approver@demo.legion.test` | Question authoring chain |
| `grader1@ / grader2@demo.legion.test` | Grader |
| `proctor@demo.legion.test` | Proctor |
| `ada@ / bode@ / chidi@ / dela@demo.legion.test` | Students |

Log in via the UI at http://localhost:3000/login, or grab a token directly:

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"officer@demo.legion.test","password":"password"}'
```

Rebuild the demo data anytime:

```bash
php artisan migrate:fresh --seed
```

---

## 7. How to add a feature

### Backend — a new endpoint

1. **Model** in `app/Modules/<Context>/Models/` (add `use BelongsToTenant;` if tenant‑scoped).
2. **Migration** in `database/migrations/` (`php artisan make:migration ...`); FK to
   `institutions` for scoped tables.
3. **Service** in `Services/` — put the logic here; throw a domain `Exception` for rule
   violations.
4. **Controller** in `Http/Controllers/` — validate input, authorize, delegate to the service,
   return JSON. Gate with either `$this->authorize(...)` (policy) or
   `abort_unless($this->permissions->can($user, Permissions::X), 403)`.
5. **Route** in `routes/api.php` under the right `prefix(...)` group (behind `Authenticate`).
6. **Permission** — if it's a new capability, add the key to `Permissions.php` (`all()` +
   grant it in `systemRoles()`).
7. **Test** in `tests/Feature/<Context>/` (PHPUnit, `RefreshDatabase`). Use the helpers in
   `tests/TestCase.php` (`makeUser`, `grantRole`, `authHeaders`, `publishSimpleAssessment`, …).
8. `./vendor/bin/pint <paths>` then `php artisan test`.

### Frontend — a new screen

1. **API helper** in `apps/web/src/lib/api.ts` (typed; returns parsed JSON).
2. **Demo data** in `apps/web/src/lib/demo.ts` (so the screen renders signed‑out).
3. **Page** in `apps/web/src/app/(app)/<route>/page.tsx`; fetch live data with
   `useApiList(loader, demoFallback)` or a `useEffect` + try/catch that falls back to demo.
4. **Nav** — add it to `apps/web/src/components/shell/Sidebar.tsx` if it's top‑level.
5. `npm run build` (in `apps/web`) to type‑check.

Study `app/Modules/Scheduling` + `apps/web/src/components/scheduling/SchedulingWorkspace.tsx`
as a full worked example (backend service + auto‑mapper + demo‑aware UI).

---

## 8. Common commands

| Task | Command |
|---|---|
| Run everything | `composer dev` |
| API only | `php artisan serve` |
| Frontend only | `cd apps/web && npm run dev` |
| Frontend build / typecheck | `cd apps/web && npm run build` |
| Migrate | `php artisan migrate` |
| Rebuild + seed demo | `php artisan migrate:fresh --seed` |
| Run tests | `php artisan test` |
| Run one test group | `php artisan test --filter=<Name>` |
| Format PHP | `./vendor/bin/pint` |
| List routes | `php artisan route:list` |
| REPL | `php artisan tinker` |

---

## 9. Troubleshooting

- **`SQLSTATE... could not connect` / `password authentication failed`** — Postgres isn't
  running or `.env` (or `phpunit.xml`) credentials are wrong. Start Postgres and match the
  `DB_*` values to a real role.
- **Tests fail with a connection/permission error** — the test DB `legion_cbt_test` doesn't
  exist, or `phpunit.xml`'s hardcoded `DB_USERNAME=user77` doesn't match your Postgres role.
  Create the DB and fix those env entries (§4).
- **`invalid input syntax for type uuid` used to appear** — fixed: route id params are now
  UUID‑constrained, so a bad/stale id 404s cleanly.
- **A screen shows fake data while signed in** — the live list came back empty or the API is
  unreachable, so it fell back to demo. Confirm you're logged in and the API is on 8000; a
  non‑UUID id in the URL (a demo slug) also triggers demo mode.
- **Frontend can't reach the API** — check `apps/web/.env.local` `NEXT_PUBLIC_API_URL` and that
  `php artisan serve` is up. CORS: the API allows the dev origin by default.
- **Queue errors on boot** — set `QUEUE_CONNECTION=sync` in `.env` if you're not running Redis.

---

## 10. Where to read next (`docs/`)

| Doc | Topic |
|---|---|
| `06-getting-started.md` | Original getting‑started notes |
| `02-system-architecture.md` | Modular monolith, bounded contexts |
| `03-database-schema.md` | Schema, tenancy, RLS |
| `04-security-architecture.md` | Auth, RBAC, the answer‑key vault |
| `07`–`16` | One doc per module (question bank, IAM, authoring, delivery, analytics, grading, certification, proctoring, reporting, notifications) |
| `17-frontend.md` | Frontend architecture & conventions |
| `18-question-banks-and-visibility.md` | Bank containers & visibility model |
| `19-users-roles-and-access.md` | **Full roles/permissions/feature‑flow reference** |
| `roles-cheatsheet.md` | One‑page role summary |
