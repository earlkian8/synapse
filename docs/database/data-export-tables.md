# Database: data export tables

The table behind [Data Export](../modules/data-export.md), created by
`2026_10_05_000000_create_data_exports_table`
([ADR 0066](../decisions/0066-data-export-a-copy-of-the-workspace-not-a-backup.md)).
It is tenant-scoped (a non-null `organization_id`, ADR 0005), omitted below for brevity.

It grew from the draft ERD's `BACKUP` entity (`disk`, `path`, `type`, `size_bytes`,
`created_by`, `completed_at`). The draft's `type` (`database|files|full`) became
`datasets` + `include_files`, because one organisation in a shared database is exported
table by table, never dumped. `created_by` became `requested_by`, the actor column the
download is restricted to.

## `data_exports`

One archive somebody asked for.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `requested_by` | FK → users, nullable | Null on delete. The only person who may download the archive. |
| `status` | string | `queued` → `building` → `ready` or `failed`; `expired` once the file is gone. Default `queued`. Indexed. |
| `format` | string | `csv` or `json`. |
| `datasets` | json | The dataset keys asked for, in catalogue order (`DataExportCatalogue::DATASETS`). |
| `include_files` | boolean | Whether the uploads the records name were copied into `files/`. |
| `disk` | string, nullable | The disk the archive is on: `exports` (private). |
| `path` | string, nullable | `organization-<id>/<uuid>.zip`, random so it cannot be guessed. |
| `filename` | string, nullable | The name it downloads as: `<slug>-data-export-<date>-<time>.zip`, on the company's clock. |
| `size_bytes` | unsigned bigint, nullable | The archive's size. |
| `summary` | json, nullable | `{rows, datasets: {<key>: {label, rows, tables: {<table>: rows}}}, files: {count, bytes, missing, skipped} \| null}`. `missing`: named but not found or not readable. `skipped`: left out once the build's time for copying files ran out. |
| `error` | text, nullable | Why a build failed, written for the person who asked. The exception itself goes to the log. |
| `started_at` | timestamp, nullable | When the build claimed it (`queued` → `building`). |
| `completed_at` | timestamp, nullable | When it finished or failed. |
| `expires_at` | timestamp, nullable | `completed_at` + 7 days (`DataExport::RETENTION_DAYS`). Indexed. Past it, the archive reads as expired even before the prune deletes the file. |
| `download_count` | unsigned int | Default 0. |
| `last_downloaded_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** `status` and `expires_at` (the prune reads across organisations), and
`(organization_id, status)` (the screen and the one-at-a-time check).

## States

- **In progress** (`scopeInProgress`) is `queued` or `building` and younger than
  `DataExport::STALE_AFTER_MINUTES` (60), counted from `started_at`, or from
  `created_at` while still queued. Only one export may be in progress per workspace.
- **Stale** (`scopeStale`) is the rest of `queued`/`building`: a build that died with
  its process. `data-export:prune` marks it `failed`.
- **Past retention** (`scopePastRetention`) is `ready` with `expires_at` passed.
  `data-export:prune` deletes the file and sets `expired`; the row stays as history.
- A `ready` row whose file has gone (a container redeploy without the private bucket)
  becomes `expired` the first time somebody tries to download it.

Deleting an export deletes the row and its file. The activity log keeps the record
that it existed (`log_name = data-export`: `created`, `downloaded`, `deleted`).

## What is not stored

The archive's contents are not in the database: they are the organisation's own rows,
read at build time. Nothing about an export is cached.
