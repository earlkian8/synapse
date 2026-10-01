# The docs catch up with the code

A documentation pass with no code changes. The ERD was still the draft proposed before
most modules were built, the index stopped at the June entries, the assistant (33
modules, 277 tools) had no module doc, and several tables had no schema reference.
This brings `docs/` level with the code as of the Docker and Supabase work.

## Highlights

- **The ERD is the built schema.** [`database/erd.md`](../database/erd.md) is rewritten
  from the database the migrations produce: every one of the 71 domain tables, by
  domain, with their columns, keys, enum values and delete behaviour. Everything the draft
  proposed that is not a table today (backups, email templates, generated reports,
  document extractions, an `ML_MODEL` table, the attendance import batch, and payroll,
  which was built and removed) is listed with what happened instead. It ends with how to check it
  against a scratch database.
- **The assistant has a module doc.** [`modules/assistant.md`](../modules/assistant.md):
  how a turn works, retrieval and the disclosure policy, tools and confirmations, the
  prompt-injection defences, every module with its permission and tools, the
  endpoints and rate limits, and how to add a module.
- **Deployment is written down.** [`deployment.md`](../deployment.md) and
  [ADR 0061](../decisions/0061-one-container-image-with-supabase-for-data-and-files.md)
  for the Docker image and Supabase, with changelog entries for both
  ([01](./2026-10-01-01-supabase-database-and-storage.md),
  [02](./2026-10-01-02-docker-image.md)).
- **The index lists everything.** [`README.md`](../README.md) now indexes all 31 module
  docs and the deployment guide, all 22 schema docs, ADRs 0001–0061 with what
  supersedes what, and every changelog entry.

## Added

- `modules/assistant.md`.
- `database/assistant-tables.md`: `assistant_conversations`, `assistant_messages`, and
  where held actions live (the cache).
- `database/identity-and-membership-tables.md`: `organization_user`,
  `employee_invitations`, `organization_join_requests`, `passkeys`, and
  `personal_access_tokens.organization_id`.
- `deployment.md`, `decisions/0061-…`, `changelog/2026-10-01-01-…`, `-02-…`, `-03-…`.

## Corrected

- `database/organizations-table.md`: `users` no longer carries `organization_id`
  (dropped by ADR 0023); which tables are and are not tenant-stamped; the missing
  `default_work_schedule_id` column; the two codes that are unique globally.
- `database/offboarding-tables.md`: it said there was no template table. Added
  `offboarding_programs`, `offboarding_program_items`, `offboarding_cases.offboarding_program_id`,
  and how a checklist is seeded and re-applied.
- Cross-links from `modules/multi-tenancy.md`, `modules/model-graduation.md` and
  `modules/mobile-app.md` to the new pages.
- Broken links in three older changelog entries (two mistyped file names, and the
  removed Payroll docs).

## Notes

- **Three ADRs still have a broken link each**, left alone because ADRs are immutable
  once merged: 0012 (`../modules/payroll.md`), 0020
  (`./0007-onboarding-provisioning.md`, meant to be `0007-onboarding-template-bridge.md`)
  and 0040 (`../modules/attendance-devices.md`, removed by ADR 0054).
- The ERD was checked against a database migrated from scratch on PostgreSQL. No tests
  were run, because no code changed.
