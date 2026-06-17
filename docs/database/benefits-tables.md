# Database: benefits tables

The tables behind the [Benefits module](../modules/benefits.md), created by
`…_create_benefits_tables` and `…_create_benefit_contributions_table` (ERD §7, the
benefits side). Two complementary halves — `benefit_plans` + `benefit_enrollments`
for program administration, and `benefit_contributions` for statutory remittance (see
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

## `benefit_contributions`

Statutory government contributions (SSS / PhilHealth / Pag-IBIG), **derived** from a
processed payroll run — never hand-entered. The employee share is the run's statutory
payslip deduction; the employer share is computed from an `employer` block on the
deduction type's `computation`. Drives the monthly remittance report
(`/benefits/contributions`). See `App\Support\Payroll\BenefitContributionGenerator`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `payroll_period_id` | FK → payroll_periods, nullable | The run it was derived from; cascade on delete. |
| `period` | string(7) | Remittance month, `YYYY-MM` (from the run's end date). |
| `benefit` | string | `sss \| philhealth \| pagibig`. |
| `employee_share` | decimal(12,2) | = the statutory deduction on the payslip. |
| `employer_share` | decimal(12,2) | The company counterpart (computed). |
| `total` | decimal(12,2) | `employee_share + employer_share`. |
| timestamps | | |

**Indexes:** unique `(payroll_period_id, employee_id, benefit)`; `(period, benefit)`;
`employee_id`.

> Regenerated whenever a run is processed / re-processed or a payslip is adjusted, so
> it stays in step with the payslip deductions (the single source of truth).
