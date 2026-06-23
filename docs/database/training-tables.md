# Database: training tables

The tables behind the [Training module](../modules/training.md), created by
`…_create_training_tables` (ERD §8, the training side). A self-contained module —
`training_programs` (created in-module, no Company-Setup config) + `training_enrollments`
— see [ADR 0013](../decisions/0013-training-and-development.md). Both are tenant-scoped
(`organization_id`).

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
| timestamps + soft deletes | | A program with enrollments cannot be permanently deleted. |

**Indexes:** `start_date`.

> The lifecycle **status is derived**, not stored: `completed` once `end_date` has
> passed, `ongoing` once `start_date` has arrived, else `upcoming`. **Seats taken** =
> non-dropped enrollments; the program is full when that reaches `capacity`.

## `training_enrollments`

One employee's enrollment in a program. Managed in the module (`/training/{program}`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `training_program_id` | FK → training_programs | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `status` | string | `enrolled \| completed \| dropped` (only non-dropped occupy a seat). |
| `score` | decimal(5,2), nullable | Completion score (0–100); null until graded. |
| `completed_at` | timestamp, nullable | Server-managed: stamped when status becomes `completed`, cleared otherwise. |
| `remarks` | text, nullable | Completion / feedback note. |
| timestamps | | |

**Indexes:** unique `(training_program_id, employee_id)` — one enrollment per employee
per program; `employee_id`.
