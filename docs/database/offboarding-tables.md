# Database: offboarding tables

The tables behind the [Offboarding module](../modules/offboarding.md). The case and its
checklist were created by `2026_06_19_000000_create_offboarding_tables`; the reusable
**programs** (clearance templates) by `2026_07_05_000000_create_offboarding_program_tables`.
All four are tenant-scoped — a non-null `organization_id` FK (ADR 0005), omitted from the
columns below for brevity. The shape mirrors the [onboarding tables](./onboarding-tables.md)
(a program, a parent case and a checklist). See
[ADR 0016](../decisions/0016-offboarding-and-clearance.md) and
[ADR 0056](../decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md).

## `offboarding_cases`

One employee's exit journey.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. **Unique** — one case per employee. |
| `offboarding_program_id` | FK → offboarding_programs, nullable | The template that seeded the checklist. Null for the built-in standard list, or after the program is deleted (`nullOnDelete`). |
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

## `offboarding_programs`

A reusable clearance template, managed in Company Setup → Offboarding Programs
(`offboarding.manage-programs`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | |
| `description` | text, nullable | |
| `department_id` | FK → departments, nullable | The departing employees it targets (their department), not who owns it. `nullOnDelete`. |
| `exit_type` | string, nullable | resignation / termination / retirement / end_of_contract. Null = any exit type. |
| `is_default` | boolean | The fallback when nothing more specific matches. |
| `is_active` | boolean | Default true. Indexed. Only active programs are matched. |
| timestamps | | |

## `offboarding_program_items`

One sign-off on a template.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `offboarding_program_id` | FK → offboarding_programs | `cascadeOnDelete`. |
| `item` | string | The sign-off label. |
| `department_id` | FK → departments, nullable | The department that signs it off (e.g. IT, Finance). `nullOnDelete`. |
| `use_employee_department` | boolean | When true, the departing employee's own department signs it off instead, resolved when the case starts. |
| `sort_order` | int | Default 0. |
| timestamps | | |

## How a checklist is seeded

Starting an exit (`OffboardingProvisioner::start()`) uses the program it was given, or
the best active match from `programFor()`, most specific first:

1. the employee's department **and** the exit type;
2. the employee's department, any exit type;
3. no department, the exit type;
4. the default program.

The chosen program's items are **copied** onto the case as `clearance_items`, so
editing or deleting the template later never changes a case already under way. When no
program matches, the case gets the built-in standard list, defined in code and routed to
departments by code or to the employee's own department. *Apply program* on a case
(`OffboardingWorkflow::applyTemplate()`) appends a template's items to an existing
checklist, skipping any whose label is already on it (case-insensitively).

## Per-tenant uniqueness

`(organization_id)` scopes every table. `offboarding_cases.employee_id` is unique
globally and therefore unique within a tenant (the employee already belongs to one).
