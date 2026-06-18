# Database: performance tables

The tables behind the [Performance module](../modules/performance.md), created by
`…_create_performance_tables` (ERD §8, plus the §2 config tables). A config layer
(`kpi_criteria` + `evaluation_periods`) and the appraisals (`performance_evaluations`
+ `performance_scores`) — see
[ADR 0012](../decisions/0012-performance-management.md). All are tenant-scoped
(`organization_id`).

## `kpi_criteria`

The Company-Setup catalogue of weighted criteria evaluations score against. Managed at
`/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Quality of Work". |
| `description` | text, nullable | What the criterion measures. |
| `weight` | decimal(6,2) | Relative share (percentage points) of the overall score. |
| `is_active` | boolean | Inactive criteria are excluded from new evaluations. |
| timestamps + soft deletes | | A criterion used by an evaluation cannot be permanently deleted. |

## `evaluation_periods`

The Company-Setup review cycles evaluations are conducted within. Managed at
`/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "H1 2026 Review". |
| `start_date` / `end_date` | date | The cycle window (`end ≥ start`). |
| `status` | string | `draft \| open \| closed`. Indexed. Evaluations open only while `open`. |
| timestamps + soft deletes | | A period with evaluations cannot be permanently deleted. |

**Indexes:** `status`, `start_date`.

## `performance_evaluations`

One employee's appraisal for a period. Addressed by hashid; managed in the Performance
module (`/performance/{evaluation}`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `evaluation_period_id` | FK → evaluation_periods | Cascade on delete. |
| `evaluator_id` | FK → users, nullable | Who conducted it; `nullOnDelete`. |
| `overall_score` | decimal(5,2), nullable | **Derived** weighted average (1–5); null until scored. |
| `status` | string | `draft \| submitted \| acknowledged`. Indexed. |
| `submitted_at` | timestamp, nullable | Set on submit (locks the card). |
| `acknowledged_at` | timestamp, nullable | Set on acknowledge. |
| `remarks` | text, nullable | Overall summary. |
| timestamps | | |

**Indexes:** unique `(employee_id, evaluation_period_id)` — one evaluation per employee
per period; `status`.

## `performance_scores`

The per-criterion breakdown a score is built from — one line per KPI criterion at the
time the evaluation was opened.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `performance_evaluation_id` | FK → performance_evaluations | Cascade on delete. |
| `kpi_criterion_id` | FK → kpi_criteria, nullable | `nullOnDelete`; lineage to the source criterion. |
| `label` | string | **Snapshot** of the criterion name, so an archived criterion still renders. |
| `weight` | decimal(6,2) | **Snapshot** of the criterion weight, so the overall stays stable. |
| `score` | decimal(5,2), nullable | The 1–5 rating; null until rated. |
| `remarks` | text, nullable | Per-criterion comment. |
| timestamps | | |

**Indexes:** `kpi_criterion_id` (the `performance_evaluation_id` FK is indexed by its
constraint).

> The overall score is derived from these lines by
> `App\Support\Performance\PerformanceScorer` (weighted average of the scored lines) —
> recomputed on every save and on submit, never stored from the client. The `label` +
> `weight` snapshot mirrors how payslip lines stay intact when a type is archived.
