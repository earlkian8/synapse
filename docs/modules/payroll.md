# Payroll

Pay runs and payslips. HR creates a **payroll run** for a pay period; the system generates
one **payslip** per active employee, computing basic / overtime / gross / deductions / net
from the employee's salary and the **attendance** recorded in the window. The data model is
ERD §7 (Payroll & Benefits) + the §2 config tables it reads from; everything is
tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/payroll` · Config: `/setup/payroll`
> Sidebar: Workforce → Payroll (gated by `payroll.view`); Company Setup → Payroll
> Configuration (gated by `setup.payroll.view`)

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
  re-deriving time), adds the employee's **active recurring allowances** as earning lines
  and **recurring deductions** (e.g. a loan) on top of the statutory ones, computes the
  figures, and persists the payslip + its lines. Re-processing replaces auto payslips but
  **preserves hand-adjusted ones** (`is_adjusted`). `buildFor()` regenerates a single
  payslip (used by "Reset to auto").
- **`PayrollCalculator`** — pure arithmetic: `basic_pay = (salary ÷ 22) × days_worked`,
  `overtime_pay = (daily ÷ 8) × 1.25 × OT hours`, and each deduction from its
  `DeductionType.computation` (a flat rate with optional floor/cap, or a progressive
  bracket). Statutory contributions (SSS / PhilHealth / Pag-IBIG) are computed on the
  period's gross so they stay proportional; **withholding tax** is a bracket on the gross
  net of those contributions.

Totals contract (one source of truth, `PayrollCalculator::totals()`):
`total_earnings` = Σ allowance earnings; `gross_pay = basic_pay + overtime_pay +
total_earnings`; `net_pay = gross_pay − total_deductions`.

## Per-employee pay items & manual editing

- **Recurring per-employee items** (`employee_allowances` / `employee_deductions`) are
  managed on the **Employee detail → Compensation** tab: pick an allowance / deduction
  type from the Company-Setup catalogue and set a per-employee peso amount (toggle active,
  remove). These drive the payslip lines, so a Setup type like "Transportation Allowance"
  only appears once it's assigned — and contractual / part-time staff can now receive
  allowances. Replaces the old hardcoded Rice/Meal defaults.
- **Manual payslip editing**: open a generated payslip (while the run is draft/processing)
  and **Edit lines** — add/remove/relabel allowance earning lines and deduction lines with
  a live net preview; basic & overtime pay stay auto. Saving flags the payslip **Adjusted**;
  a re-process leaves it untouched, and **Reset to auto** regenerates it from salary +
  attendance. Blocked once the run is finalized / paid.

> The model is deliberately simple and deterministic for a believable demo — a monthly
> salary spread over a standard 22-day month. It **approximates** Philippine statutory
> rules; it is not a legally-exact payroll engine.

## Lifecycle

`draft` → **processing** (payslips generated) → **finalized** (locked from changes) →
**paid** (every payslip released to its employee). Open runs can be re-processed or
deleted; finalized/paid runs cannot.

## Configuration (`/setup/payroll`)

Company Setup → **Payroll Configuration** manages the lookups the engine reads:
**allowance types** (name + taxable) and **deduction types** (name, kind, mandatory flag,
and a flat percentage rate + optional monthly cap that assembles the `computation` config).
Both support create / edit / archive (soft delete) / restore / permanent delete; each row
shows how many payslips reference it. A deduction that uses a progressive **bracket** (e.g.
withholding tax) is shown read-only and only replaced if a rate is set.

## Permissions

`payroll.view` (runs & payslips), `payroll.process` (create / re-process / delete runs),
`payroll.release` (finalize, mark paid, release payslips), `payroll.adjust` (per-employee
pay items + manual payslip editing); `setup.payroll.view` / `setup.payroll.manage` (the
configuration surface). Built-in **HR Manager** gets all of them.

## Out of scope (this cut)

Deferred from the ERD: `benefit_contributions`, an employee self-service payslip view, and
an assistant capability.
