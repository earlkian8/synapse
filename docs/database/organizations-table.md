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
| `default_work_schedule_id` | FK → work_schedules, nullable | The company's default hours: the last fallback when an employee has no roster override, assignment, location or department schedule ([ADR 0037](../decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)). Null on delete. |
| `attendance_closed_from` | date, nullable | The first date the end-of-day job ever closed ([ADR 0041](../decisions/0041-attendance-days-close-themselves.md)). A change of leave, holiday or roster never writes a missing day before it, so the first run after deploy does not back-fill history. |
| `attendance_closed_through` | date, nullable | The last date the job closed: records written for everybody due at work, forgotten clock-outs handled, the digest sent. Advances over contiguous dates only; the next run starts the day after (at most seven days back). |
| `tin` / `sss_employer_no` / `philhealth_employer_no` / `pagibig_employer_no` | string, nullable | Employer government IDs. |
| `join_code` / `join_code_enabled` | string / boolean | The code people type to ask to join (ADR 0026). A credential, so not `$fillable`. |
| `setup_completed_at` | timestamp, nullable | Null means guided setup is still owed — see [ADR 0032](../decisions/0032-guided-company-setup.md). Organisations that predate the wizard were back-filled as complete. |
| `setup_steps` | json, nullable | `{step key: "done"｜"skipped"}` for the wizard's steps — one per Company Setup screen ([ADR 0044](../decisions/0044-the-setup-wizard-carries-every-company-setup-screen.md)); anything absent reads as pending. |
| timestamps + `deleted_at` | | |

> `setup_completed_at` and `setup_steps` are **not** `$fillable`: like `join_code` they
> are tenant state, written only by `Support\Setup\CompanySetup`, never by an edit to
> the company profile.

## The `organization_id` column

Every tenant-owned table carries it: indexed, FK → `organizations` with
`cascadeOnDelete`, and stamped on create by the `BelongsToOrganization` trait. The
`…_add_multi_tenancy` migration added it to the tables that existed then (`roles`,
`employees`, `departments`, `positions`, `work_schedules`, `employee_documents`,
`employee_certifications`, `employee_promotions`, `activity_logs`, and at the time
`users`); every module table created since has it from the start. It is **non-null**
everywhere except:

- `activity_logs` — **nullable** (system events may have no tenant);
- `personal_access_tokens` — **nullable**, added by ADR 0023 to bind a mobile token to
  its active workspace (see [identity & membership tables](./identity-and-membership-tables.md)).

**`users` no longer has the column.** ADR 0023 dropped it: a user is a global
identity, and belongs to organisations through the `organization_user` membership
pivot.

> Not stamped at all: `users`, `permissions`, the `permission_role` / `role_user`
> pivots, `employee_work_locations`, `passkeys`, and the framework `notifications` /
> `push_subscriptions` tables. Permissions are global; the pivots inherit isolation
> from their already-scoped sides; notifications, push subscriptions and passkeys are
> reached only through their user. The [ERD](./erd.md) lists every table.

## Per-tenant uniqueness

The migration drops these global unique indexes and replaces them with composite ones,
so the same value may recur across tenants:

| Table | Was | Now |
| --- | --- | --- |
| `roles` | `name` | `(organization_id, name)` |
| `departments` | `code` | `(organization_id, code)` |
| `employees` | `employee_no` | `(organization_id, employee_no)` |

`users.email` stays **globally** unique — login resolves a user without a tenant hint.

Later modules follow the same rule: a code or name that must be unique is unique per
organisation (`leave_types.code`, `roles.name`, `employees.user_id`, …). Two are
unique **globally** on purpose, because they are typed before any tenant is known:
`organizations.join_code` and `employee_invitations.code`.

## Existing data

On an install that already held data, the migration creates a single
**"Default Organization"** and backfills every existing row into it, so nothing is
lost. A fresh install starts with no organisation; the first registration (or the
seeder's demo organisation) creates one.
