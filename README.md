# Legion CBT — Assessment Operating System

Legion is a multi-tenant Examination, Assessment, Proctoring, Question-Banking,
Analytics, and Certification platform designed to serve institutions ranging from
a single department (100 users) to national-scale testing (1M+ concurrent candidates).

This repository is being built **incrementally and correctly** — each module is
designed to run, not merely to look complete. The current phase delivers the
architectural foundation and database design that everything else is built on.

## Tech Stack (decided)

| Concern          | Technology                                   |
|------------------|----------------------------------------------|
| Backend          | **Laravel 12** (PHP 8.3+)                    |
| Frontend         | Next.js + TypeScript                         |
| Primary database | PostgreSQL 16                                |
| Cache / queues   | Redis 7                                      |
| Search           | OpenSearch                                   |
| Object storage   | S3-compatible (MinIO in dev)                 |
| Realtime         | WebSockets (Laravel Reverb / Soketi)         |
| Containers       | Docker + Kubernetes                          |
| CI/CD            | GitHub Actions                               |
| Observability    | Prometheus + Grafana, ELK                    |

## Documentation map

The architecture is documented in [`docs/`](docs/), read in this order:

1. [Domain Analysis](docs/01-domain-analysis.md) — DDD bounded contexts, aggregates, ubiquitous language.
2. [System Architecture](docs/02-system-architecture.md) — service topology, scaling strategy, infrastructure.
3. [Database Schema & ERD](docs/03-database-schema.md) — the data model with entity-relationship diagrams.
4. [Security Architecture](docs/04-security-architecture.md) — the zero-trust, split-key answer-protection model.
5. [Proctoring Architecture](docs/05-proctoring-architecture.md) — AI monitoring, lockdown browser, risk scoring.
6. [Getting Started](docs/06-getting-started.md) — bring the schema up locally or via Docker.
7. [Question Bank Module](docs/07-question-bank-module.md) — the first implemented module (with tests).
8. [IAM Module](docs/08-iam-module.md) — authentication, scoped RBAC, and MFA.
9. [Assessment Authoring Module](docs/09-assessment-authoring-module.md) — blueprints, scoring rules, paper assembly.
10. [Exam Delivery & Scoring Module](docs/10-delivery-scoring-module.md) — sittings, variants, append-only responses, JIT vault scoring.
11. [Analytics & Psychometrics Module](docs/11-analytics-psychometrics-module.md) — item stats, KR-20/α/SEM, difficulty feedback loop.
12. [Manual / AI Grading Workflow](docs/12-grading-workflow.md) — double-marking, reconciliation, advisory AI.
13. [Certification Module](docs/13-certification-module.md) — verifiable certificates, public portal, tamper-evidence.
14. [Proctoring Module](docs/14-proctoring-module.md) — sessions, flags, explainable risk scoring, human review.
15. [Reporting Module](docs/15-reporting-module.md) — PDF/Excel/CSV reports from the read models.
16. [Notifications Module](docs/16-notifications-module.md) — idempotent multi-channel, event-driven.
17. [Frontend (Next.js)](docs/17-frontend.md) — premium glassmorphic web client in `apps/web/`.
18. [Question Banks & Visibility](docs/18-question-banks-and-visibility.md) — bank containers, org/shared/group privacy, course/specialization/tags.

## Quick start

```bash
cp .env.example .env && php artisan key:generate
docker compose up --build        # Postgres + Redis + app, migrations run on boot
# — or, against a local Postgres/Redis —
createdb legion_cbt && php artisan migrate && php artisan serve
```

Seed an explorable end-to-end demo (Demo University, full exam lifecycle):

```bash
php artisan migrate:fresh --seed     # prints logins + a sample certificate token + curl examples
```

See [docs/06-getting-started.md](docs/06-getting-started.md) for verification commands.

## Code map (current)

```
docs/                       architecture & design documentation
database/migrations/        Laravel migrations — the executable database schema (runs on Postgres)
app/ bootstrap/ config/     Laravel 12 application skeleton
docker-compose.yml          Postgres 16 + Redis 7 + app (portable run path)
Dockerfile                  single app image, configured per process-role via env
apps/web/                   Next.js 16 frontend — premium glassmorphic web client
```

As implementation proceeds, application code (models, services, APIs, `apps/web/`) is
added module by module, each phase building on the foundation in `docs/`.

**Status:** the full schema (all 13 bounded contexts) migrates cleanly against
PostgreSQL and the security primitives (vault separation, append-only audit, RLS,
partitioning) are verified live. Application modules and the frontend are the next
phases. Nothing is claimed working until it runs and is tested.

## Build philosophy

- **Bounded contexts over a monolith of tables.** Each context owns its data and
  exposes contracts, so contexts can later be extracted into independent services.
- **Security is structural, not bolted on.** Answer keys are never co-located with
  questions; see the split-key model in the security doc.
- **Every claim in the docs maps to a migration or a service.** Documentation that
  doesn't correspond to runnable artifacts is marked clearly as *future work*.
