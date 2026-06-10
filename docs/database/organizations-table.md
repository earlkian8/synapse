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
| `tin` / `sss_employer_no` / `philhealth_employer_no` / `pagibig_employer_no` | string, nullable | Employer government IDs. |
| timestamps + `deleted_at` | | |

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
