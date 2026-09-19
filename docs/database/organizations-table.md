# Database: `organizations` & the tenant column

The tenant root behind [multi-tenancy](../modules/multi-tenancy.md). Added by the
`…_add_multi_tenancy` migration, which also stamps every tenant-owned table with an
`organization_id` and converts global unique constraints to composite ones (ADR 0005).

## `organizations`

The tenant — also the company profile (there is no separate `company_profiles`).
Soft-deletes.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | Display name, set at registration. |
| `slug` | string, unique | URL-safe, globally unique (future subdomains). |
| `legal_name` | string, nullable | |
| `logo` | string, nullable | Stored on the `public` disk; exposed as `logo_url`. |
| `email` / `phone` / `address` | string/text, nullable | |
| `timezone` | string(64), default `Asia/Manila` | IANA zone attendance is judged on — see [ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md). Existing organisations were back-filled by the default. |
| `attendance_period_frequency` | string, default `semi_monthly` | The calendar attendance periods are generated on: `weekly`, `bi_weekly`, `semi_monthly` (1–15, 16–end) or `monthly` — see [ADR 0039](../decisions/0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md). Set on the attendance board's Periods tab. |
| `attendance_lock_reminder_days` | smallint, default 2 | How many days before an open period ends its managers are reminded to lock it. |
| `attendance_closed_from` | date, nullable | The first date the end-of-day job ever closed ([ADR 0041](../decisions/0041-attendance-days-close-themselves.md)). A change of leave, holiday or roster never writes a missing day before it, so the first run after deploy does not back-fill history. |
| `attendance_closed_through` | date, nullable | The last date the job closed: records written for everybody due at work, forgotten clock-outs handled, the digest sent. Advances over contiguous dates only; the next run starts the day after (at most seven days back). |
| `tin` / `sss_employer_no` / `philhealth_employer_no` / `pagibig_employer_no` | string, nullable | Employer government IDs. |
| `join_code` / `join_code_enabled` | string / boolean | The code people type to ask to join (ADR 0026). A credential, so not `$fillable`. |
| `setup_completed_at` | timestamp, nullable | Null means guided setup is still owed — see [ADR 0032](../decisions/0032-guided-company-setup.md). Organisations that predate the wizard were back-filled as complete. |
| `setup_steps` | json, nullable | `{step key: "done"｜"skipped"}` for the wizard's five steps; anything absent reads as pending. |
| timestamps + `deleted_at` | | |

> `setup_completed_at` and `setup_steps` are **not** `$fillable`: like `join_code` they
> are tenant state, written only by `Support\Setup\CompanySetup`, never by an edit to
> the company profile.

## The `organization_id` column

Added to every tenant-owned table, indexed, FK → `organizations` with
`cascadeOnDelete`:

`users`, `roles`, `employees`, `departments`, `positions`, `work_schedules`,
`employee_documents`, `employee_certifications`, `employee_promotions` — **non-null**.

`activity_logs` — **nullable** (system events may have no tenant).

> Permissions, the `permission_role` / `role_user` pivots, and the framework
> `notifications` / `push_subscriptions` tables are **not** stamped: permissions are
> global; the pivots inherit isolation from their already-scoped sides; notifications
> are reached only through their (scoped) notifiable user.

## Per-tenant uniqueness

The migration drops these global unique indexes and replaces them with composite ones,
so the same value may recur across tenants:

| Table | Was | Now |
| --- | --- | --- |
| `roles` | `name` | `(organization_id, name)` |
| `departments` | `code` | `(organization_id, code)` |
| `employees` | `employee_no` | `(organization_id, employee_no)` |

`users.email` stays **globally** unique — login resolves a user without a tenant hint.

## Existing data

On an install that already held data, the migration creates a single
**"Default Organization"** and backfills every existing row into it, so nothing is
lost. A fresh install starts with no organisation; the first registration (or the
seeder's demo organisation) creates one.
