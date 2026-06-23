# Seeders: complete demo data coverage for every module

Rounds out the seeded dataset so every module — and the System surfaces — has data
to exercise. Two gaps were closed and the demo/mock seeders now share the new pieces.

## Gaps

- **Employee profile sub-records were seeded nowhere.** `EmployeeDocument`,
  `EmployeeCertification` and `EmployeePromotion` had no factory and no seeder, so the
  Employee detail drawer's **Documents**, **Certifications** and **Promotions** tabs were
  always empty in every tenant. (Allowances/deductions were already covered by
  `PayrollSeeder`.)
- **The demo tenant lacked the System surfaces.** `DatabaseSeeder` seeded a single
  account and no activity-log trail or in-app notifications, so **User Management**,
  **Roles**, **Activity Logs** and the notification bell were thin. That data only existed
  in `MockSeeder` (as private methods), against a different account.

## Changes

- **New `EmployeeProfileSeeder`** — tenant-aware, idempotent *per employee* (so it tops up
  a growing roster on re-run). Seeds:
  - 2–4 documents per employee (contract, government IDs, plus CV / NBI / COE for variety),
  - one or two certifications for ~70% of staff (one deliberately expired, to exercise the
    "Expired" badge),
  - one promotion for each employee with 18+ months tenure — from a lower role in the same
    department into their current position with a salary bump; the most recent hires keep
    an empty history so the "no promotions" state is testable too.
- **New `SystemSeeder`** — extracts `MockSeeder`'s extra-users / activity-log / notification
  logic into a reusable, tenant-aware seeder. Attributes the activity + notification trail
  to the tenant's super-admin account (resolved via `Role::SUPER_ADMIN`, falling back to the
  first user), and seeds an HR Manager + two Staff accounts so the Users/Roles surfaces have
  a roster.
- **Wiring** — both new seeders are called by `DatabaseSeeder` (after the module seeders)
  and `MockSeeder` (after the roster is finalised). `MockSeeder` now delegates to
  `SystemSeeder` and its three duplicated private methods were removed.
- Added an `events` line to the seeded activity trail.

## Notes

- Reset & reseeded via `php artisan migrate:fresh --seed`. The demo tenant
  (`dev@synapse.com`, Super Admin) now reports data for every table: documents 88,
  certifications 26, promotions 22, events 6 + 126 attendees, activity logs 10, plus the
  pre-existing module data (payslips, benefit enrollments/contributions, evaluations,
  training, awards, leave, attendance, recruitment, onboarding).
- `employee_documents.file` is a non-null column, so documents are seeded with placeholder
  storage paths — the rows render fully; the download link points at a file that isn't on
  disk.
- The seeders remain idempotent: re-running tops up only what's missing (e.g. promotions for
  newly-added employees) without duplicating existing rows.
- `php -l` + Pint green across all four touched seeders.
