# Training & Development module

Adds the **Training & Development** module (ERD §8): run training programs and track
employee enrollments through to completion, with a scored, derived lifecycle. Wires the
last Workforce sidebar placeholder. Reuses the Benefits "catalogue + enrollments"
pattern, but programs are created in-module (no Company-Setup config). See
[ADR 0013](../decisions/0013-training-and-development.md),
[module doc](../modules/training.md) and
[training tables](../database/training-tables.md).

## Highlights

- **Programs grouped by a derived status.** A program is `upcoming → ongoing →
  completed` based on its date window (never stored); the overview groups cards by it
  and surfaces seat usage + completions.
- **Enrollment lifecycle with completion scoring.** Enroll an employee, then move them
  `enrolled → completed | dropped` with a 0–100 score; `completed_at` is stamped /
  cleared server-side from the status. Enrolling is blocked when a program is full or
  the employee is already enrolled.
- **In-module management.** No Company-Setup surface — create / edit / archive / restore
  / permanently delete programs from the module itself (a program with enrollments can't
  be permanently deleted).

## Backend

- **Migration** `…_create_training_tables`: `training_programs` (soft-deletes; nullable
  `end_date` / `capacity`) and `training_enrollments` (unique per program+employee). All
  tenant-scoped.
- **Models** `TrainingProgram` (derived `status()` + `isFull()`), `TrainingEnrollment`
  (+ `Employee::trainingEnrollments`).
- **Controllers** `Training\TrainingController` (index/show) + `TrainingProgramController`
  (CRUD + archive/restore/force) + `TrainingEnrollmentController` (enroll/update/remove,
  with server-managed `completed_at`). Thin, FormRequest-validated, activity-logged.
- **Resources** `TrainingProgramResource` (status + seat/completion aggregates),
  `TrainingEnrollmentResource`.
- **Routes** new `routes/training.php` (required in `web.php`). Permissions
  `training.view` / `training.manage` added to `PermissionRegistry`; built-in HR Manager
  granted both.
- **Seeder** `TrainingSeeder` (5 programs across the lifecycle incl. a self-paced course,
  + enrollments with completions/scores); wired into `DatabaseSeeder`.
- **Employee integration** `EmployeeController` eager-loads `trainingEnrollments.program`;
  `EmployeeResource` exposes `training_enrollments`.

## Frontend

- **Feature** `features/training` (types, routes, api, constants, components: stats,
  status badges, program form sheet, enroll dialog, program card).
- **Pages** `training/index` (stats + programs grouped by status + archived toggle),
  `training/show` (program roster + enroll + edit/archive).
- **Employee detail** read-only **Training** tab (alongside Benefits & Performance).
- **Sidebar** Training & Development wired + gated (`training.view`).

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint, Prettier and `vite build` all green; routes
  registered; migration + seeders run on Postgres (5 programs, 70 enrollments, derived
  statuses + seat counts correct); the enroll → complete (stamping) → full-guard →
  hashid-binding flow and the employee Training-tab serialization validated via a
  rolled-back tinker transaction. Pest was **not** run locally (no `pdo_sqlite`).
- Out of scope this cut: per-session calendars/attendance, certificates & expiry,
  budgets, training-needs analysis, self-enrollment, and an assistant capability.
