# 0066 — Data Export: a copy of the workspace's records, not a backup

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:**
  - [0061 — One container image, with Supabase for the database and the files](./0061-one-container-image-with-supabase-for-data-and-files.md)
    (why the archive cannot sit in the upload bucket, or only on the container's disk);
  - [0063 — Privacy Policy and Terms of Service](./0063-privacy-policy-and-terms-of-service.md)
    (which promise an organisation can export its records before it leaves);
  - [0057 — Users, roles, the audit trail and the trash bin join the assistant](./0057-assistant-users-roles-activity-and-trash.md)
    (the rule that a cross-module screen composes the owning modules' permissions);
  - module doc: [Data Export](../modules/data-export.md); schema:
    [data export tables](../database/data-export-tables.md).

## Context

The sidebar has carried a *Data Backup & Export* link to `/system/backup` since the first
draft, with no screen behind it, and the draft ERD had a `BACKUP` table
(`type: database|files|full`). Meanwhile the Privacy Policy and the Terms promise that
"when an organisation stops using SYNAPSE, it can export its records first", and that
people can receive their information "in a structured, commonly used electronic
format". The per-module CSV exports cover a list at a time, not a workspace.

Production runs on Supabase (ADR 0061), which shapes everything here:

1. **The database is shared by every organisation, and Supabase backs it up.** A
   "backup" button could not dump it (that would be every tenant's data) and should not
   pretend to restore one tenant into it.
2. **The upload bucket is public.** Supabase has no per-object ACLs, so the bucket is
   marked public and any stored file is readable by URL. An archive of a workspace's
   personal data cannot go there.
3. **The container's disk is wiped on every deploy**, and there may be no queue worker
   in development (the reason `RecomputeAttendanceRange` and `SystemNotification` avoid
   one).

## Decision

**An export, named as one.** The screen is *Data Export* at `/system/data-export`. It
writes one ZIP of the organisation's own rows: a folder per dataset, a file per table
(CSV or JSON), the uploaded files when asked for, a `manifest.json` for software and a
`README.txt` for people. Backups stay the database host's job, and the screen says so.

**Datasets follow the screens, and so do permissions.** `DataExportCatalogue` groups
every tenant table into 22 datasets in the sidebar's order. Each is guarded by the view
permission of the screen its records are read on (`employees.view`,
`attendance.view`, …), so an export never hands anyone more than the screens do. Two
new permissions gate the screen itself: `data-export.view` (the history) and
`data-export.create` (prepare, download your own, delete). Both go to HR Managers.

**Raw rows, explicitly confined.** Each table is read with the query builder, every
column, archived rows included, ids kept so files link up. The organisation is applied
by each table's `scope` (`organization_id`, membership for users, the organisation's
roles and locations for pivots) and never left to the tenant global scope, which is a
no-op when nothing is bound. A test fails if a table with an `organization_id` is
neither exported nor listed in `DataExportCatalogue::EXCLUDED` with its reason.

**Secrets are never exported.** Password hashes, remember tokens, two-factor secrets and
recovery codes, email verification codes, invitation tokens and codes, the join code,
and mobile access tokens are left out. So are each person's assistant conversations and
notifications, which are theirs and not the company's records.

**Only the requester downloads, for seven days.** An archive is built from the
requester's access, so only they may take it, and only while they still hold every
permission it was built with. Others with the screen see that it exists and who made
it. Every request, download and delete is in the activity log. After 7 days
`data-export:prune` (hourly) deletes the file and keeps the row as history.

**A private disk.** Archives go to the `exports` disk: a private Supabase bucket when
`SUPABASE_EXPORTS_BUCKET` is set (same keys as uploads), otherwise
`storage/app/private/exports`. Nothing is served by URL. Every download streams through
the app after its checks, so no presigned or public link exists to leak.

**Built after the response, without a worker.** `BuildDataExport` is dispatched with
`dispatchAfterResponse`, like `RecomputeAttendanceRange`. The build claims the export
(`queued` → `building`) before writing, so a second run does nothing. One export at a
time per workspace, held by a lock so two clicks cannot both pass. An export queued or
building for over an hour reads as failed at once and is recorded as failed (with its
requester notified) by the prune, which also sweeps the working directory a killed
build left. So a build that died with its process blocks nothing.

**A build fits the worker it runs in.** PHP-FPM kills a request's worker at 600 s, and
copying thousands of punch selfies one round trip at a time can take longer. Uploads are
copied only for the first 420 s of a build (`ArchiveBuilder::FILE_BUDGET_SECONDS`).
After that the rest are counted as skipped and named in the README and on the screen,
and there is time left to close and store the archive. Copies are stored in the ZIP,
not deflated: photos and PDFs are compressed already.

**One moment, not seventy.** On Postgres the archive is read inside one `REPEATABLE
READ, READ ONLY` transaction, so its tables agree with each other even while people
keep working.

**Safe in a spreadsheet.** CSV files start with a UTF-8 byte-order mark (Excel and
Filipino names), and a text value starting with `=`, `+`, `-` or `@` gets a leading
apostrophe. That matters here: applicants type their own details on the public careers
page. JSON keeps values exactly as stored.

## Consequences

- **Without `SUPABASE_EXPORTS_BUCKET`, a redeploy loses ready archives.** The row then
  reads as expired the first time someone tries to download it. Production should set
  the bucket; it must be **private**.
- **A build holds a PHP-FPM worker after the response** and runs within
  `request_terminate_timeout` (600 s). That is ample for an HR workspace's rows. With
  uploads it can leave files out (counted, and said so) rather than fail. To lift that
  limit, run the job on the queue worker the image already runs. The job is
  `ShouldQueue` and idempotent, but moving it takes three changes, not one:
  1. dispatch it with `dispatch()`;
  2. give `BuildDataExport` a `$timeout` (`queue:work` defaults to 60 s);
  3. raise the database connection's `retry_after` (90 s in `config/queue.php`) above
     that timeout, or the job is handed out again while it is still running.
  Then raise `FILE_BUDGET_SECONDS` to match.
- **On SQLite, JSON columns export as text** in the JSON format, because SQLite reports
  them as `text`. Production (Postgres) nests them.
- The assistant does not prepare or download exports. ADR 0059 keeps import and export
  out of chat. The system guide only points to the screen.
- Adding a table that carries `organization_id` now means deciding where it exports, or
  saying why it does not. The catalogue test enforces it.

- **Route bindings were not tenant-confined.** The tenant middleware was appended
  after `SubstituteBindings`, so on a real request every `{model}` binding was resolved
  before a tenant was bound, and the tenant scope did nothing. Any organisation's
  record could be reached by its id or hashid, across the web app and the mobile API.
  Tests could not see it, because they keep the test's tenant bound. Building this
  module's isolation tests exposed it. `bootstrap/app.php` now puts
  `EnsureAccountIsActive` and `SetCurrentOrganization` ahead of `SubstituteBindings` in
  the middleware priority. `TenancyTest` checks it with no tenant pre-bound. The export
  controller checks the organisation again as well.

## Alternatives considered

- **`pg_dump` of the database, or a "restore" button.** The database is every tenant's.
  A per-tenant dump with a restore into a shared, live schema would need ID remapping
  across 70 tables and would be dangerous to get slightly wrong. Supabase's backups
  cover disaster recovery.
- **Building the archive in the request and streaming it.** Simplest, with nothing
  stored. But a large workspace with uploads can outlast a request, there would be no
  record of what was taken, and a dropped connection would lose all the work.
- **Presigned download links.** Supabase's S3 API supports them, but a link is a
  capability that can be forwarded, and the requester's access is re-checked on every
  download. Streaming through the app keeps that check.
- **Curated, human-labelled columns (report style).** That would be friendlier in a
  spreadsheet, but lossy and different from what is stored. Raw columns make the archive
  a faithful copy that another system can import. The per-module exports and Reports
  remain for readable lists.
