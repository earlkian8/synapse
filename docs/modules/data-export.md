# Module: Data Export

> Status: **Active** · Route prefix: `/system/data-export` · Sidebar: System → Data Export

A copy of the organisation's records as one ZIP archive: to keep, to hand to an
auditor, or to move to another system, including before the organisation stops using
SYNAPSE (as the Privacy Policy and Terms promise). It replaces the sidebar's old
*Data Backup & Export* placeholder. The database is backed up by its host (Supabase),
so this module exports and does not back up
([ADR 0066](../decisions/0066-data-export-a-copy-of-the-workspace-not-a-backup.md)).

---

## 1. What it does

| Feature | Notes |
| --- | --- |
| **Choose datasets** | Every kind of record the viewer may see, grouped by sidebar section, each with its record count today. All start ticked. |
| **Format** | CSV (spreadsheets) or JSON (other systems). |
| **Uploaded files** | Optional. Photos, résumés, documents, certificates and punch selfies the chosen records name. |
| **Archive preview** | The folders the archive will unzip into, live as datasets are ticked. |
| **Background build** | Written after the response. The screen polls every 3 s while one is in progress, and the requester gets a notification when it is ready or failed. |
| **History** | The last 25 archives in the workspace: status, contents, size, who and when, how long it is kept, downloads. |
| **Download** | The requester only, for 7 days. Streamed through the app. |
| **Delete** | Anyone with `data-export.create`, once it is no longer in progress. |

### The archive

```
<slug>-data-export-<YYYY-MM-DD-HHMMSS>.zip
├── README.txt        what is inside, who made it, how to read it (CRLF, for Notepad)
├── manifest.json     version, organisation, requester, format, every file's columns and rows, what was left out
├── <dataset>/<table>.csv|json
└── files/<stored path>   when uploads were included
```

- **Raw rows**: every column as stored (less the secrets below), archived rows
  included, ids kept so tables link up. Times are UTC.
- **CSV**: UTF-8 with a byte-order mark. A text value starting with `= + - @` (or a tab
  or carriage return) that isn't a number gets a leading `'`. Booleans read `true` or
  `false`, and nulls are empty.
- **JSON**: an array of objects per table. Booleans are booleans, `json` columns are
  nested (Postgres only; SQLite reports them as text), and decimals stay strings.
- **Files**: copied from the `public` disk under the path the record names, and stored
  in the ZIP without compression. A URL (a seeded photo) is skipped. A file that can't
  be found or read is counted in `summary.files.missing`. A path containing `..` is
  never followed. After `ArchiveBuilder::FILE_BUDGET_SECONDS` (420 s) of a build, the
  remaining files are counted in `summary.files.skipped` and named in the README, so a
  build always finishes inside PHP-FPM's 600 s.
- **Consistent**: on Postgres every table is read in one `REPEATABLE READ, READ ONLY`
  transaction, as of one moment.

---

## 2. Datasets

Declared in [`app/Support/DataExport/DataExportCatalogue.php`](../../server/app/Support/DataExport/DataExportCatalogue.php).
Each is guarded by the view permission of its screen.

| Section | Key | Permission | Tables |
| --- | --- | --- | --- |
| Talent Acquisition | `recruitment` | `recruitment.view` | recruitment_pipelines, recruitment_pipeline_stages, job_postings, job_posting_screening_questions, applicants 📎, applicant_documents 📎, job_applications, interviews |
| | `onboarding` | `onboarding.view` | onboarding_programs, onboarding_program_tasks, onboarding_cases, onboarding_tasks |
| Workforce | `employees` | `employees.view` | employees 📎, employee_documents 📎, employee_certifications 📎, employee_promotions, employee_invitations, organization_join_requests |
| | `attendance` | `attendance.view` | attendance_records, attendance_punches 📎 |
| | `leave` | `leave.view` | leave_types, leave_balances, leave_requests |
| | `performance` | `performance.view` | rating_scales, kpi_criteria, review_templates, review_template_items, goal_templates, evaluation_periods, performance_evaluations, performance_scores, appraisal_reviews, appraisal_review_scores, performance_goals, goal_check_ins, calibration_sessions, calibration_participants, calibration_adjustments |
| | `training` | `training.view` | training_programs, training_enrollments |
| | `awards` | `awards.view` | award_types, employee_awards |
| | `events` | `events.view` | events, event_attendees |
| Offboarding | `offboarding` | `offboarding.view` | offboarding_programs, offboarding_program_items, offboarding_cases, clearance_items |
| Analytics & AI | `attrition` | `analytics.attrition.view` | attrition_risk_runs, attrition_risk_scores, local_models (attrition) |
| | `performance-forecast` | `analytics.performance.view` | performance_forecast_runs, performance_forecasts, local_models (performance) |
| | `promotion-readiness` | `analytics.promotion.view` | promotion_readiness_runs, promotion_readiness_scores, local_models (promotion) |
| Company Setup | `company` | `setup.company.view` | organizations (its own row) 📎 |
| | `structure` | `setup.departments.view` | departments, positions |
| | `schedules` | `setup.schedule.view` | work_schedules, work_schedule_days, holidays |
| | `roster` | `setup.roster.view` | employee_schedule_assignments, shift_roster_entries |
| | `attendance-policies` | `setup.attendance-policies.view` | attendance_policies |
| | `locations` | `setup.locations.view` | work_locations, employee_work_locations |
| System | `users` | `users.view` | users (members) 📎, organization_user |
| | `roles` | `roles.view` | roles, role_user, permission_role, permissions (the global catalogue) |
| | `activity-logs` | `activity-logs.view` | activity_logs |

📎 has a file column that is copied when uploads are included.

**Never exported.** Columns: `users.password`, `remember_token`,
`two_factor_secret`, `two_factor_recovery_codes`, `email_verification_code(_expires_at)`;
`employee_invitations.token`, `code`; `organizations.join_code`. Tables
(`DataExportCatalogue::EXCLUDED`): `assistant_conversations`, `assistant_messages`
(each person's own), `personal_access_tokens` (credentials), `data_exports` (this
screen's history). Global and framework tables (`notifications`, `push_subscriptions`,
`passkeys`, `sessions`, `jobs`, …) carry no `organization_id`.

**Confinement.** Each table spec names a `scope`: `tenant` (`organization_id`, the
default), `self` (the organisation row), `members` (users with a membership),
`roles` / `work_locations` / `calibration_sessions` (pivot rows of the organisation's
roles, locations or calibration sessions), or
`global`. It is applied explicitly in `DataExportCatalogue::query()`, never left to the
tenant global scope.

### Adding a table

A new table with an `organization_id` fails
`every table that holds a workspace's records is exported or deliberately left out`
until it is added to a dataset (with `exclude` for any secret columns and `files` for
upload paths) or to `EXCLUDED` with the reason.

---

## 3. Permissions

| Permission | Grants | Seeded to |
| --- | --- | --- |
| `data-export.view` | The screen and the history. Sidebar item. | HR Manager |
| `data-export.create` | Preparing archives, downloading your own, deleting any finished one. | HR Manager |

On top of the gates, per export (`DataExports`):

- **Request**: every dataset must pass `DataExportCatalogue::allows()`. A dataset the
  asker cannot view is a validation error on `datasets.N`.
- **Download**: the export is `ready` and within `expires_at` (404 otherwise), the
  viewer is the requester, still holds `data-export.create`, **and still holds every
  dataset's permission** (403 otherwise).
- **Delete**: `data-export.create`, and the export isn't in progress.
- One export in progress per workspace, held by a cache lock so concurrent requests
  cannot both pass. A second request gets an error toast naming whose export is running.
- An export queued or building for over `STALE_AFTER_MINUTES` (60) reads as **failed**
  at once (`DataExport::isStale()`): it no longer blocks a new export or keeps the
  screen polling, and can be deleted.
- Download and delete also check that the export is this workspace's, on top of the
  route binding.

The migration publishes both permissions and grants them to every existing HR Manager
role. New organisations get them through `OrganizationProvisioner` (HR Manager holds
every permission).

---

## 4. Routes

Defined in [`server/routes/system.php`](../../server/routes/system.php) under
`['auth', 'verified']`, name prefix `system.data-export.*`. Exports are addressed by
hashid.

| Method | URI | Name | Gate |
| --- | --- | --- | --- |
| GET | `/system/data-export` | `index` | `data-export.view` |
| POST | `/system/data-export` | `store` | `data-export.create` |
| GET | `/system/data-export/{dataExport}/download` | `download` | `data-export.create` + requester checks |
| DELETE | `/system/data-export/{dataExport}` | `destroy` | `data-export.create` |

Console: `data-export:prune`, scheduled hourly (`routes/console.php`). It deletes
archives past retention (`expired`), records stale exports as `failed` and notifies their
requesters, and sweeps `storage/app/private/tmp/data-export-*` directories older than an
hour, left by killed builds.

**Bindings and the tenant.** `{dataExport}` resolves by hashid through the tenant
scope. That holds because `SetCurrentOrganization` runs before `SubstituteBindings`
(the middleware priority in `bootstrap/app.php`; see ADR 0066's consequences).

---

## 5. Backend architecture

```
server/app/
├── Models/DataExport.php                         # statuses, RETENTION_DAYS, STALE_AFTER_MINUTES, scopes
├── Http/Controllers/System/DataExportController.php   # index, store, download, destroy (thin)
├── Http/Requests/DataExport/StoreDataExportRequest.php
├── Http/Resources/DataExportResource.php         # history row + can_download / can_delete
├── Jobs/BuildDataExport.php                      # dispatched after the response; claims, then builds
├── Console/Commands/PruneDataExports.php         # data-export:prune
└── Support/DataExport/
    ├── DataExportCatalogue.php                   # datasets, permissions, scopes, exclusions
    ├── ArchiveBuilder.php                        # streams tables + files into the ZIP; manifest; README
    ├── DataExports.php                           # request, build, download, delete, prune, the rules
    └── DataExportException.php                   # a refusal fit to show
```

**Flow.** `store` → `DataExports::request()` (under a per-workspace lock) creates a `queued` row, logs `created` and
dispatches `BuildDataExport` after the response. The job calls `DataExports::build()`,
which atomically claims the row (`queued` → `building`; a second run does nothing),
binds the tenant, and has `ArchiveBuilder` write the ZIP in
`storage/app/private/tmp/data-export-*`. It streams the ZIP to the `exports` disk at
`organization-<id>/<uuid>.zip`, marks the row `ready` with `expires_at`, deletes the
temp directory and notifies the requester (category `data-export`). Any exception is
reported, an archive already stored is deleted, the row is marked `failed` with a
readable reason, and the requester is notified. The builder runs inside
`DataExports::snapshot()` (a read-only repeatable-read transaction on Postgres).

**Streaming.** Tables are read with `lazyById(1000)` (pivots with `lazy()` in key
order) and written row by row to a temp file, so memory stays flat. Column types come
from `Schema::getColumns()`, driver-aware for booleans (`bool` on Postgres,
`tinyint(1)` on SQLite) and JSON (`json`/`jsonb`).

**Storage.** The `exports` disk in `config/filesystems.php` is a private Supabase
bucket (`SUPABASE_EXPORTS_BUCKET`, sharing the `SUPABASE_STORAGE_*` keys) or
`storage/app/private/exports`. It has `throw => true`, so a failed write fails the
build instead of producing a ready export with no file. Downloads use
`Storage::download()` with `Cache-Control: no-store, private`. A download whose file
has gone marks the export `expired` and flashes an error.

**Activity log** (`log_name = data-export`): `created` (datasets, format, files),
`downloaded` (datasets, size), `deleted`.

---

## 6. Frontend

Feature folder `resources/js/features/data-export/`:

- `types.ts`, `routes.ts`, `api.ts` (delete), `constants.ts` (status badges, the
  section order, byte/date formatting, `POLL_INTERVAL_MS`);
- `hooks/use-export-composer.ts`: the `useForm` state for datasets, format and files,
  section toggles, and the submit (which drops `include_files` when nothing chosen
  has uploads);
- `components/dataset-picker.tsx`: the grouped, checkable list with record counts;
- `components/archive-panel.tsx`: the sticky panel with the archive tree preview, the
  format choice, the uploads switch, the privacy note and **Prepare archive**;
- `components/export-history.tsx`: the history rows, the status badge and
  `describeDatasets()`.

The page `pages/system/data-export/index.tsx` uses `usePoll` (only `exports`) while any
export is queued or building. `datasets` and `archive_name` are lazy props, so the poll
does not recount every dataset. Deleting goes through the shared `ConfirmDialog`. A
viewer without `data-export.create` sees the history and a note, and no picker.

---

## 7. Integration

- **Sidebar**: System → Data Export (`data-export.view`), with a tour summary, so the
  product tour now lists it.
- **Help Center**: *Administration → Exporting your company's data*
  (`resources/help/administration/data-export.md`), the help for `/system/data-export`.
- **Assistant**: the system guide describes the screen. The assistant does not prepare
  or download exports (ADR 0059 keeps import/export out of chat).
- **Deployment**: set `SUPABASE_EXPORTS_BUCKET` to a **private** bucket in production
  (see [Deployment](../deployment.md)).

---

## 8. Testing

[`server/tests/Feature/DataExport/`](../../server/tests/Feature/DataExport):

- `DataExportTest.php`: the screen (datasets by permission, record counts, the
  view-only case), requesting (builds, notifies, logs; a forbidden dataset is refused;
  validation; one at a time), downloading (requester only, access re-checked, expiry,
  a missing file), deleting, and another workspace's exports out of reach.
- `DataExportArchiveTest.php`: the archive (tenant isolation, archived rows, members
  only, secrets absent, manifest and README, JSON, CSV formula safety and BOM,
  uploaded files with missing ones counted, a failed build), and the catalogue
  (every tenant table covered, every table and permission real).
- `DataExportPruneTest.php`: expiry across workspaces, stale exports failed with their
  requester notified, and leftover working directories swept.
- `Tenancy/TenancyTest.php`: a record of another organisation is not reached by its URL
  on a request that starts with no tenant bound.
