# Database: payroll tables

The tables behind the [Payroll module](../modules/payroll.md), created by
`…_create_payroll_tables` (ERD §7, plus the two Company-Setup config tables of ERD §2).
All are tenant-scoped (`organization_id`). Money is `decimal(12,2)`.

## `allowance_types` · `deduction_types` (config)

Company-Setup lookups the payslip lines are typed by.

**`allowance_types`** — `id`, `organization_id`, `name`, `is_taxable` (bool), timestamps,
soft deletes.

**`deduction_types`** — `id`, `organization_id`, `name`, `kind`
(`sss | philhealth | pagibig | withholding_tax | loan | other`), `is_mandatory` (bool),
`computation` (json — the rate/bracket config the calculator interprets), timestamps,
soft deletes.

## `payroll_periods`

One pay run.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Jun 1–15, 2026". |
| `start_date` / `end_date` | date | The period covered. |
| `pay_date` | date | When pay is disbursed. |
| `status` | string | `draft \| processing \| finalized \| paid`. |
| `processed_by` | FK → users, nullable | Who ran it. |
| timestamps + soft deletes | | |

**Indexes:** `status`; `start_date`.

## `payslips`

One employee's computed pay for a period. Every money figure is derived server-side from
the lines (see `App\Support\Payroll`), never trusted from the client.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant. |
| `payroll_period_id` | FK → payroll_periods | Cascade on delete. |
| `employee_id` | FK → employees | Cascade on delete. |
| `basic_pay` | decimal(12,2) | Daily rate × days worked. |
| `overtime_pay` | decimal(12,2) | Hourly rate × 1.25 × OT hours. |
| `gross_pay` | decimal(12,2) | `basic_pay + overtime_pay + total_earnings`. |
| `total_earnings` | decimal(12,2) | Σ of the allowance earning lines. |
| `total_deductions` | decimal(12,2) | Σ of the deduction lines. |
| `net_pay` | decimal(12,2) | `gross_pay − total_deductions`. |
| `days_worked` | decimal(6,2) | Worked days drawn from attendance. |
| `status` | string | `draft \| released`. |
| timestamps | | |

**Indexes:** unique `(payroll_period_id, employee_id)`; `employee_id`.

## `payslip_earnings` · `payslip_deductions`

The itemised lines a payslip is built from. Each row: `id`, `organization_id`,
`payslip_id` (cascade), an optional type FK (`allowance_type_id` / `deduction_type_id`,
null on delete), a `label`, and an `amount` (decimal(12,2)), plus timestamps. The
`payslip_earnings` lines are the **allowances** only — basic pay and overtime live in their
own payslip columns.

> Deferred from ERD §7/§3 for this cut: `benefit_contributions`, recurring
> `employee_allowances`, and an employee self-service payslip view.
