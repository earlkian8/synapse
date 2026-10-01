# Every page renders, and the empty board stops giving bad advice

A verification pass over the whole app after the appraisal-framework rebuild
([2026-08-12-01](2026-08-12-01-appraisal-frameworks-and-tenant-rating-models.md)),
prompted by a `migrate` failure that turned out to be a database left over from an
unreleased draft rather than anything in the code.

Nothing was broken. The pass is worth keeping anyway, because the reason nothing
was broken is not something the test suite could previously have told anyone.

## Every page renders

`tests/Feature/PageSmokeTest.php` walks **every page in the app** as a
fully-permitted user and asserts it renders the Inertia component it should,
walks every CSV download and asserts it streams, then re-walks the whole list as
a user holding no permissions to prove the gates hold. 81 assertions over 35
pages, 12 downloads and every gate between them.

The per-module tests assert what a page *says*. Nothing asserted that a page
comes back at all — and pages break for reasons no module test is looking at: a
prop a controller stopped sending, a resource reading a column a migration
dropped, a component removed from under an import. That whole class of failure is
invisible until somebody clicks.

Walking it found no defects. It did pin four behaviours that were previously only
implied, and are now asserted rather than assumed:

- `attendance.me` answers **403 until the account is linked to a roster line** —
  holding every permission in the catalogue does not stand in for having one.
- `security.edit` sits behind a **password confirmation**, so it redirects rather
  than rendering.
- The assistant conversation list answers **JSON**, not a page.
- The **reports hub is open to any signed-in user**, because each report
  re-authorises individually and the catalogue simply shows less.

## The empty Performance board gave the wrong advice

An empty board said *"launch the cycle to open an appraisal for everyone"* even
when the tenant had no framework to launch one with — sending somebody to a modal
whose only possible answer was to refuse them. It now names the actual blocker
(no framework → no open cycle → nothing appraised yet) and links to the surface
that fixes it.

This is the state every brand-new tenant is in: `OrganizationProvisioner` seeds
no module defaults for *any* module, so Performance starts empty exactly as Leave
and Recruitment do. That is left alone deliberately; the empty state is what
needed to be honest about it.

## Six silent actions, now documented as silent

An audit walked all **208 mutating endpoints** to their controller methods: 202
flash a toast, six deliberately do not. Four of the six carried no note saying
why, which is how a deliberate silence becomes indistinguishable from an
oversight. Behaviour is unchanged; the reasons are now in the code:

- `NotificationController::read` / `readAll` / `destroy` — the row visibly loses
  its unread dot, or disappears. (`clear()` does toast: emptying the whole list is
  a bulk action worth confirming.)
- `ProfileController::destroy` — the session is invalidated on the way out, so a
  flash would be written to a session nobody reads again.

## Notes

- **If `migrate` fails with `relation "employee_invitations" already exists`**,
  the database is carrying a leftover from an unreleased draft of the ADR 0026
  migration: a table with `status` / `last_sent_at` / `sent_count` /
  `declined_at` columns the current schema does not have, sitting *outside* the
  migration ledger while the migration that creates the real one still reads
  `Pending`. Drop it and re-run `migrate` — it is always empty, and nothing can
  be reading it while its migration has not run. The migration is deliberately
  **not** guarded with `hasTable`, because a guard would leave the wrong-shaped
  table standing and break invitations silently later.
- The appraisal flow was also exercised end-to-end against the real development
  database after migrating it — resolve framework, open, score, band, calibrate —
  inside a rolled-back transaction, so the flow is proven on migrated data
  without writing to it. The ML feature mappers were checked against the same
  backfilled rows and still read the 1–5 index unchanged.
- **618 tests, 618 passing** (537 before).
