# 06 — Getting Started (running the foundation)

The schema in [`03-database-schema.md`](03-database-schema.md) is **runnable**. This
doc shows the two ways to bring it up. Both end with the full schema (47 public tables,
the `vault` schema, partitioned `responses`/`audit_entries`, the audit-immutability
trigger, and Row-Level Security on tenant tables) live in PostgreSQL.

## Prerequisites

- PHP 8.3+ and Composer
- Either a local PostgreSQL 16 + Redis 7, **or** Docker (for the compose path)

## Option A — Docker (portable, no local services needed)

```bash
cp .env.example .env
php artisan key:generate           # if APP_KEY is empty
docker compose up --build          # starts postgres + redis + app, runs migrate
```

The app serves on http://localhost:8000. Postgres is exposed on `5432`, Redis on `6379`.

## Option B — Local native (uses an existing Postgres/Redis)

```bash
cp .env.example .env
php artisan key:generate
createdb legion_cbt                # or use an existing database
# edit .env: DB_USERNAME / DB_PASSWORD for your local Postgres
php artisan migrate
php artisan serve
```

## Verifying the schema landed

```bash
# Tables (public + vault)
psql -d legion_cbt -c "\dt public.*"
psql -d legion_cbt -c "\dt vault.*"

# Partitions of the hot/append-only tables
psql -d legion_cbt -c "SELECT inhrelid::regclass FROM pg_inherits WHERE inhparent='responses'::regclass;"

# Prove the audit log is append-only (UPDATE/DELETE are rejected by a trigger)
psql -d legion_cbt -c "INSERT INTO audit_entries(action, entry_hash) VALUES('x','h');"
psql -d legion_cbt -c "UPDATE audit_entries SET action='y' WHERE action='x';"   # -> ERROR: append-only
```

## What runs today vs. what's designed

| Area | Status |
|------|--------|
| Full database schema (all 13 contexts) | **Runs** — `php artisan migrate` against Postgres |
| Vault schema separation, partitioning, audit immutability trigger, RLS policies | **Runs & verified** |
| Application modules (models, services, APIs) | Next phase — built module by module on this schema |
| KMS/HSM split-key crypto, proctoring inference service, Next.js frontend | Designed (docs 04, 05); built in later phases |

## Notes

- **Laravel version:** pinned to `laravel/framework: ^12.0` (running 12.62.0) per the
  spec. The migrations use only stable Schema/DB APIs, so they are framework-version
  agnostic across 11/12/13.
- **Session/cache/queue drivers** are set to `file`/`file`/`redis` so Laravel's own
  session table does not collide with our domain `sessions` table. In production,
  sessions move to Redis and cache to Redis per the architecture doc.
- **Row-Level Security** is enforced for non-superuser DB roles. A local superuser
  bypasses RLS by design; in production the app connects as a non-superuser role and the
  policies key off the `app.current_institution` GUC set by tenant middleware.
