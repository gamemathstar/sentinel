# 15 — Reporting Module

Generates **PDF / Excel / CSV** report artifacts from the finalized read models
(scores, item statistics, reliability, risk) — off the transactional hot path. Each run
is recorded as a `reports` row pointing at the stored file, which is then downloadable.

Covered by **11 reporting tests**, including real-artifact assertions (whole suite:
**115 passing**).

## Code layout

```
app/Modules/Reporting/
  Models/Report.php
  Support/ReportCatalog.php            report types + formats + mime/extension
  Services/
    ReportDataBuilder.php              builds uniform tabular data per type
    ReportingService.php               build -> render -> store -> Report row
  Renderers/
    ReportRenderer.php (interface)
    CsvRenderer.php                    native PHP
    XlsxRenderer.php                   PhpSpreadsheet (real .xlsx)
    PdfRenderer.php                    dompdf (real PDF)
  Exceptions/ReportingError.php
  Http/Controllers/ReportController.php
database/migrations/...create_reports_table.php
```

## Report types

| Type | Source read models | Content |
|------|---------------------|---------|
| `results` | sittings + scores + users | per-candidate raw/scaled score + status |
| `item_quality` | item_statistics | facility, discrimination, sample N per item |
| `assessment_summary` | reliability + scores | title/kind/candidates/mean + KR-20/α/SEM |
| `risk` | proctoring sessions + risk assessments | per-candidate cheating probability + top signal |

All four are exercised by tests against a fully graded, analyzed, partly-flagged
assessment.

## Build → render → store

`ReportDataBuilder` returns a **uniform** shape — `{title, columns, rows, meta}` — so any
renderer consumes the same data:

- **CSV** — native `fputcsv`, no dependency.
- **XLSX** — PhpSpreadsheet, a real workbook (tests assert the `PK` zip signature and a
  non-trivial size).
- **PDF** — dompdf from a styled HTML table (tests assert the `%PDF` signature);
  `isRemoteEnabled` is off so report HTML can't fetch external resources.

`ReportingService.generate` builds, renders, writes the file to the `local` disk under
`reports/{institution}/{id}.{ext}`, and records a `reports` row (`completed`, row count,
title, path). On failure the row is marked `failed` with the error. It reads only — never
the transactional path (docs/02 §5).

## API (under `/api/reporting`, authenticated)

| Method & path | Permission | Purpose |
|---------------|-----------|---------|
| `POST /reports` | `reporting.generate` | generate (`type`, `format`, `params.assessment_id`) |
| `GET /reports` | `reporting.read` | list generated reports (tenant-scoped) |
| `GET /reports/{id}` | `reporting.read` | report metadata |
| `GET /reports/{id}/download` | `reporting.read` | download the artifact |

`exam_officer` holds both permissions; a `student` is forbidden (403, tested). An unknown
type/format returns **422**.

## Notes / future work

- Generation is synchronous here; at scale it becomes a queued job (the service body is
  already isolated, and could be triggered by `ScoreFinalized` / `RiskAssessed`).
- Institution / accreditation / cross-cohort comparison reports and a custom-report
  builder are additional `ReportDataBuilder` types over the same renderer pipeline.
- Artifacts are stored on the `local` disk in dev; production points the disk at
  S3-compatible storage (docs/02 §5) — no code change, just disk config.
