# 2026-06-12 — Employee profile photos everywhere

Employees now carry a real profile photo, and **every module that surfaces an
employee renders it** instead of a bare initials avatar. See the
[Employees module](../modules/employees.md).

## Summary

- A shared **`PersonAvatar`** component is the single source of truth for the
  photo-or-initials treatment (brand-navy initials fallback). Every employee
  avatar across the app now routes through it.
- Employees are seeded with **gender-matched demo portraits** so the directory,
  leave inbox, onboarding board and org chart look populated out of the box.
- The employee create/edit form gets a first-class **photo block** (preview /
  change / remove) matching the User form, replacing the bare file input.
- Closed the remaining display gaps: the **leave balances** card and the
  **department head** (org tree + detail drawer) now show the photo.

## Backend

- **`Employee::photoUrl()`** accessor — the one place that turns a stored `photo`
  into a servable URL: an absolute URL (seeded demo portraits) is passed through
  unchanged; a stored path is resolved on the `public` disk. Returns `null` when
  unset.
- Resources now read `photo_url` instead of inlining `Storage::disk()->url()`:
  `EmployeeResource`, `LeaveRequestResource`, `OnboardingCaseResource`, and
  `DepartmentResource` (which now also exposes the head's `photo`).
- `LeaveBalanceController@index` passes each employee's `photo_url` through to the
  balances view.
- `OrganizationSeeder` backfills a stable, gender-matched
  `randomuser.me/api/portraits/{men|women}/{id%100}.jpg` for every employee still
  missing a photo (idempotent — safe to re-run; only fills the gaps). Real
  uploads continue to store local files on the `public` disk.

> The photo column, upload/replace/remove handling (`EmployeeController`) and
> validation (`image|mimes:jpg,jpeg,png,webp|max:2048`) already existed from the
> Employees module — this change makes employees *have* photos and makes every
> consumer *show* them.

## Frontend

- New `resources/js/components/person-avatar.tsx` (`name`, `initials`, `photo`,
  plus `className` / `fallbackClassName` for sizing). `EmployeeAvatar` now
  delegates to it.
- Migrated the inline avatars onto `PersonAvatar`: leave request row, leave review
  drawer, onboarding case card, onboarding case page, leave **balances** card, and
  the department **head** (tree node + detail drawer).
- `employee-form-sheet.tsx`: photo preview block — circular-ish preview (falls
  back to initials), Upload / Change + Remove buttons, hidden image input, object
  URLs revoked on change, `remove_photo` wired.
- Types: `LeaveEmployee` already carried `photo`; added `photo` to
  `EmployeeBalance` and `DepartmentHead`.

## Verification

`tsc`, ESLint and Pint clean; `npm run build` succeeds. Against live Postgres:
all 26 seeded employees have a photo; `photo_url` passes absolute URLs through and
resolves local paths to `…/storage/employee-photos/…`; the department-head
resource exposes the photo. (Feature suite needs `pdo_sqlite` / CI.)

## ⚠️ Seed note

Re-run `php artisan db:seed --class=OrganizationSeeder` to backfill demo portraits
on existing employees (idempotent — only fills employees with no photo).
