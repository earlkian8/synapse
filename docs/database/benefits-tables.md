# Database: benefits tables

The tables behind the [Benefits module](../modules/benefits.md), created by
`…_create_benefits_tables` (ERD §7, the benefits side). They replace the deferred
`benefit_contributions` snapshot with a plan + enrollment model (see
[ADR 0011](../decisions/0011-benefits-administration.md)). All are tenant-scoped
(`organization_id`). Money is `decimal(12,2)`.

## `benefit_plans`

The Company-Setup catalogue of benefit plans the organisation offers. Managed at
`/setup/benefits`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Maxicare HMO – Standard". |
| `category` | string | `hmo \| insurance \| retirement \| wellness \| other`. Indexed. |
| `provider` | string, nullable | e.g. "Maxicare". |
| `description` | text, nullable | Coverage summary. |
| `employee_cost` | decimal(12,2) | The employee's share per period. |
| `employer_cost` | decimal(12,2) | The employer's share per period. |
| `frequency` | string | `monthly \| quarterly \| annual \| one_time` — drives the monthly-equivalent cost rollups. |
| `is_active` | boolean | Inactive plans are hidden from new enrollments. |
| timestamps + soft deletes | | A plan with enrollments cannot be permanently deleted. |

## `benefit_enrollments`

One employee's enrollment in a plan. Managed in the Benefits module
(`/benefits/{plan}`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `benefit_plan_id` | FK → benefit_plans | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `status` | string | `active \| pending \| waived \| terminated` (only `active` counts for coverage + cost rollups). |
| `reference_no` | string, nullable | Member / policy number. |
| `enrolled_on` | date, nullable | Coverage start. |
| `ended_on` | date, nullable | Coverage end. |
| `notes` | text, nullable | |
| timestamps | | |

**Indexes:** unique `(benefit_plan_id, employee_id)` — one enrollment per employee
per plan; `employee_id`.

> Cost rollups normalise each plan's per-period cost to a **monthly equivalent**
> (`quarterly ÷ 3`, `annual ÷ 12`, `one_time` excluded) × its active enrollee count.
