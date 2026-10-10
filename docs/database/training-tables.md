# Database: training tables

The tables behind the [Training module](../modules/training.md), created by
`…_create_training_tables` (ERD §8, the training side). A self-contained module —
`training_programs` (created in-module, no Company-Setup config) + `training_enrollments`
— see [ADR 0013](../decisions/0013-training-and-development.md).
`2026_10_11_000000_create_training_self_service_tables` adds joining and requests, the
sessions and attendance ([ADR 0074](../decisions/0074-training-self-enrolment-sessions-and-attendance.md)),
certificates on `employee_certifications`, costs and budgets
([ADR 0075](../decisions/0075-training-certificates-with-expiry-and-budgets-with-costs.md)).
Every table is tenant-scoped (`organization_id`).

## `training_programs`

A training program / cohort. Managed in the module (`/training`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Leadership Essentials". |
| `description` | text, nullable | What the program covers. |
| `provider` | string, nullable | e.g. "Dale Carnegie", "In-house". |
| `start_date` | date, nullable | Window start; null = self-paced / open-ended. |
| `end_date` | date, nullable | Window end (`≥ start`). |
| `capacity` | unsigned int, nullable | Seat capacity; null = uncapped. |
| `enrollment_mode` | string(16), default `closed` | `closed \| open \| approval` — who may join themselves (ADR 0074). |
| `issues_certificate` | boolean, default false | Completing it issues a certificate (ADR 0075). |
| `certificate_validity_months` | unsigned smallint, nullable | Months a certificate is valid; null = never expires. Cleared when `issues_certificate` is off. |
| `budget` | decimal(12,2), nullable | What the program may spend, in pesos. |
| `ai_insights` | json, nullable | The saved AI effectiveness read. |
| timestamps + soft deletes | | A program with enrollments cannot be permanently deleted. |

**Indexes:** `start_date`.

> The lifecycle **status is derived**, not stored: `completed` once `end_date` has
> passed, `ongoing` once `start_date` has arrived, else `upcoming`. **Seats taken** =
> enrolled + completed enrollments (a request takes none); the program is full when
> that reaches `capacity`. Spend is never stored: it is the sum of `training_costs`.

## `training_enrollments`

One employee's enrollment in a program. Managed in the module (`/training/{program}`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `training_program_id` | FK → training_programs | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `status` | string | `requested \| enrolled \| completed \| dropped \| declined`. The roster is enrolled / completed / dropped; enrolled and completed hold a seat; a request (`requested`, then `enrolled` or `declined`) is neither. |
| `score` | decimal(5,2), nullable | Completion score (0–100); null until graded. |
| `completed_at` | timestamp, nullable | Server-managed: stamped when status becomes `completed`, cleared otherwise. |
| `remarks` | text, nullable | Completion / feedback note. |
| `decided_by` | FK → users, nullable | Who approved or declined a request. `nullOnDelete`. |
| `decided_at` | timestamp, nullable | When. |
| `decision_note` | text, nullable | Why it was declined; shown to the person. |
| timestamps | | `created_at` of a request is when it was asked. |

**Indexes:** unique `(training_program_id, employee_id)` — one row per employee per
program, a request included; `employee_id`; `(training_program_id, status)`.

## `training_sessions`

One sitting of a program (ADR 0074), on the organisation's wall clock.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by id. |
| `organization_id` | FK → organizations | Tenant. |
| `training_program_id` | FK → training_programs | Cascade on delete. |
| `title` | string, nullable | e.g. "Day 1: Foundations". |
| `session_date` | date | Inside the program's dates. |
| `starts_at` / `ends_at` | time | Local clock-face times; `ends_at` after `starts_at`. |
| `location` | string, nullable | Free text (a room, or a meeting link). |
| `notes` | text, nullable | |
| timestamps | | |

**Indexes:** `(training_program_id, session_date)`, `(organization_id, session_date)`.

## `training_attendances`

One enrollee at one session (ADR 0074).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `training_session_id` | FK → training_sessions | Cascade on delete. |
| `training_enrollment_id` | FK → training_enrollments | Cascade on delete. |
| `status` | string(16) | `present \| late \| absent \| excused`. Present and late count as attended; excused is left out of the rate. |
| `note` | string(500), nullable | |
| `marked_by` | FK → users, nullable | `nullOnDelete`. |
| timestamps | | |

**Indexes:** unique `(training_session_id, training_enrollment_id)`;
`training_enrollment_id`.

## `training_costs`

What a program spent (ADR 0075).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by id. |
| `organization_id` | FK → organizations | Tenant. |
| `training_program_id` | FK → training_programs | Cascade on delete (a soft-deleted program keeps its costs). |
| `category` | string(24) | `trainer_fee \| venue \| materials \| travel \| meals \| certification \| other`. |
| `description` | string, nullable | |
| `amount` | decimal(12,2) | Pesos, > 0. |
| `incurred_on` | date | Decides the year it counts against. |
| `recorded_by` | FK → users, nullable | `nullOnDelete`. |
| timestamps | | |

**Indexes:** `(training_program_id, incurred_on)`, `(organization_id, incurred_on)`.

## `training_budgets`

The organisation's training budget for one calendar year (ADR 0075).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `year` | unsigned smallint | |
| `amount` | decimal(12,2) | |
| `notes` | text, nullable | |
| timestamps | | |

**Indexes:** unique `(organization_id, year)`.

## Certificates on `employee_certifications`

A certificate earned in training is an ordinary
[`employee_certifications`](./employees-tables.md#employee_certifications) row with
three columns added by the same migration:

| Column | Type | Notes |
| --- | --- | --- |
| `training_enrollment_id` | FK → training_enrollments, nullable, unique | Set for a certificate earned by completing a program; cascade on delete. |
| `certificate_no` | string(40), nullable | `TC-<year>-<id>`. |
| `expiry_notified_at` | timestamp, nullable | When its holder was told it expires soon (once). |

Its standing (valid, expiring within 60 days, expired, renewed) is derived, never
stored.
