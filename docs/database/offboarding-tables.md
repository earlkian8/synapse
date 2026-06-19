# Database: offboarding tables

The tables behind the [Offboarding module](../modules/offboarding.md), created by the
`…_create_offboarding_tables` migration. Both are tenant-scoped — a non-null
`organization_id` FK (ADR 0005), omitted from the columns below for brevity. The shape
mirrors the [onboarding tables](./onboarding-tables.md) (a parent case + a checklist).
See [ADR 0016](../decisions/0016-offboarding-and-clearance.md).

## `offboarding_cases`

One employee's exit journey.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. **Unique** — one case per employee. |
| `type` | string | resignation / termination / retirement / end_of_contract. |
| `notice_date` | date, nullable | When notice was given / served. |
| `last_working_day` | date, nullable | Effective separation date. Indexed. |
| `reason` | text, nullable | Context for the exit. |
| `status` | string | initiated / clearance / completed / cancelled. Indexed. |
| `completed_at` | timestamp, nullable | Stamped when the exit is finalised. |
| timestamps | | |

> **`clearance_status` is not a column** — it is **derived** from the items
> (`pending → in_progress → cleared`) by `OffboardingProvisioner::clearanceStatus()`,
> so it cannot drift (the same derive-don't-store norm as onboarding progress). The
> ERD §9 enum is preserved; only its storage is dropped (ADR 0016).

## `clearance_items`

A single clearance sign-off on a case.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `offboarding_case_id` | FK → offboarding_cases | `cascadeOnDelete`. |
| `item` | string | The sign-off label (e.g. "Return laptop & peripherals"). |
| `department_id` | FK → departments, nullable | The responsible department. `nullOnDelete`. |
| `status` | string | pending / cleared / flagged. Indexed. `flagged` = an outstanding issue blocks the exit. |
| `remarks` | text, nullable | Sign-off note or the reason an item is flagged. |
| `cleared_by` | FK → users, nullable | Who signed it off. `nullOnDelete`. |
| `cleared_at` | timestamp, nullable | When it was signed off. |
| `sort_order` | int | Default 0. |
| timestamps | | |

## Per-tenant uniqueness

`(organization_id)` scopes both tables. `offboarding_cases.employee_id` is unique
globally and therefore unique within a tenant (the employee already belongs to one).
The standard clearance checklist is **instantiated** onto a case at start time by
`OffboardingProvisioner` (no template table — the baseline list is code-defined,
routed to departments by code or to the employee's own department).
