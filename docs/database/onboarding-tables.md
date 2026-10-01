# Database: onboarding tables

The tables behind the [Onboarding module](../modules/onboarding.md), created by the
`…_create_onboarding_tables` migration. Every table is tenant-scoped — a non-null
`organization_id` FK (ADR 0005), omitted from the columns below for brevity.

## `onboarding_programs`

A reusable template, optionally targeted at a department / employment type.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | |
| `description` | text, nullable | |
| `department_id` | FK → departments, nullable | Targets hires in this department. `nullOnDelete`. |
| `employment_type` | string, nullable | regular / probationary / contractual / part_time. |
| `is_default` | boolean | The fallback program. At most one per tenant (enforced in the controller). |
| `is_active` | boolean | Default true. Indexed. Inactive programs are never auto-assigned. |
| timestamps | | |

## `onboarding_program_tasks`

A program's blueprint tasks — copied onto a case when onboarding starts.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `onboarding_program_id` | FK → onboarding_programs | `cascadeOnDelete`. |
| `title` | string | |
| `description` | text, nullable | |
| `category` | string | paperwork / equipment / access / orientation / training / compliance / other. |
| `due_offset_days` | smallint | Default 7. Days **after the case start** the task is due. |
| `sort_order` | int | Default 0. |

## `onboarding_cases`

One employee's onboarding journey.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. **Unique** — one case per employee. |
| `onboarding_program_id` | FK → onboarding_programs, nullable | The seeding template. `nullOnDelete`. |
| `status` | string | pending / in_progress / completed / cancelled. Indexed. |
| `start_date` | date | Defaults to the hire date. |
| `target_end_date` | date, nullable | Latest task due date at start; editable. |
| `completed_at` | timestamp, nullable | Stamped when marked complete. |
| `notes` | text, nullable | |
| timestamps | | |

## `onboarding_tasks`

A concrete checklist item on a case.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `onboarding_case_id` | FK → onboarding_cases | `cascadeOnDelete`. |
| `title` | string | |
| `description` | text, nullable | |
| `category` | string | Same set as blueprint tasks. |
| `assigned_to` | FK → users, nullable | The responsible actor. `nullOnDelete`. |
| `due_date` | date, nullable | Indexed. Computed as `start_date + due_offset_days` at instantiation. |
| `status` | string | pending / in_progress / done / skipped. Indexed. `done`+`skipped` count as resolved. |
| `completed_at` | timestamp, nullable | Stamped when marked done. |
| `completed_by` | FK → users, nullable | Who marked it done. `nullOnDelete`. |
| `sort_order` | int | Default 0. |

## Per-tenant uniqueness

`(organization_id)` scopes every table. `onboarding_cases.employee_id` is unique
globally and therefore unique within a tenant (the employee already belongs to one).
Blueprint tasks are **copied** onto a case at start time, so later program edits do not
affect in-flight cases (ADR 0007).
