# Payroll

Pay runs and payslips. HR creates a **payroll run** for a pay period; the system generates
one **payslip** per active employee, computing basic / overtime / gross / deductions / net
from the employee's salary and the **attendance** recorded in the window. The data model is
ERD §7 (Payroll & Benefits) + the §2 config tables it reads from; everything is
tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/payroll`
> Sidebar: Workforce → Payroll (gated by `payroll.view`)

## Surfaces

- **`/payroll`** — the **runs overview**: a **KPI bar** (latest net pay, employees paid,
  latest deductions, pending runs), status tabs (All / Draft / Processing / Finalized /
  Paid), and a **hero card per run** (period, status, net/gross/headcount totals, and the
  next lifecycle action). "New run" creates a period and processes it.
- **`/payroll/{period}`** — a **run's payslips**: a header with the run's totals and
  lifecycle actions (re-process / finalize / mark paid / delete), then a per-employee
  **payslip table** — days worked, gross, deductions, net, release status — with a
  **collapsible itemized breakdown** per row. "View" opens the payslip as a
  **document** (organization letterhead, employee + period block, itemized earnings and
  deductions, net-pay footer).

## How it computes

Canonical support classes in `app/Support/Payroll` (reused by the controller and the
seeder, so a run behaves identically everywhere):

- **`PayrollProcessor`** — for each active employee with a salary, derives `days_worked`
  and overtime **from the `attendance_records` in the period** (reusing the DTR rather than
  re-deriving time), computes the figures, and persists the payslip + its lines.
  Re-processing is idempotent (existing payslips are replaced).
- **`PayrollCalculator`** — pure arithmetic: `basic_pay = (salary ÷ 22) × days_worked`,
  `overtime_pay = (daily ÷ 8) × 1.25 × OT hours`, and each deduction from its
  `DeductionType.computation` (a flat rate with optional floor/cap, or a progressive
  bracket). Statutory contributions (SSS / PhilHealth / Pag-IBIG) are computed on the
  period's gross so they stay proportional; **withholding tax** is a bracket on the gross
  net of those contributions.

Totals contract: `total_earnings` = Σ allowance earnings; `gross_pay = basic_pay +
overtime_pay + total_earnings`; `net_pay = gross_pay − total_deductions`.

> The model is deliberately simple and deterministic for a believable demo — a monthly
> salary spread over a standard 22-day month. It **approximates** Philippine statutory
> rules; it is not a legally-exact payroll engine.

## Lifecycle

`draft` → **processing** (payslips generated) → **finalized** (locked from changes) →
**paid** (every payslip released to its employee). Open runs can be re-processed or
deleted; finalized/paid runs cannot.

## Permissions

`payroll.view` (runs & payslips), `payroll.process` (create / re-process / delete runs),
`payroll.release` (finalize, mark paid, release payslips). Built-in **HR Manager** gets all
three.

## Out of scope (this cut)

Deferred from the ERD: `benefit_contributions`, recurring `employee_allowances`, the
`/setup/payroll` configuration CRUD (the allowance/deduction types are seeded), an employee
self-service payslip view, and an assistant capability.
