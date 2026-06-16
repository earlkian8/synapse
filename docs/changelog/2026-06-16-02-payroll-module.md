# 2026-06-16 — Payroll module

The sidebar linked **Workforce → Payroll** but nothing was behind it. This builds the
**Payroll & Benefits** module (ERD §7): HR creates a **payroll run** for a pay period and
the system generates a **payslip** per active employee, computing basic / overtime / gross
/ deductions / net from each employee's salary and the **attendance** in the window. The
`employees.basic_salary` column and the DTR finally have a downstream consumer.

## Highlights

- **Runs overview** (`/payroll`) — a **KPI bar** (latest net pay, employees paid, latest
  deductions, pending runs), status tabs, and a **hero card per run** (period, status,
  net/gross/headcount, next lifecycle action). "New run" creates and processes a period.
- **Run detail** (`/payroll/{period}`) — totals header + lifecycle actions (re-process /
  finalize / mark paid / delete) and a per-employee **payslip table** with a **collapsible
  itemized breakdown** per row.
- **Payslip document** — "View" opens a payslip styled as a document: organization
  letterhead, employee + period block, itemized earnings and deductions, net-pay footer.
- **Real computation** — payslips are derived from attendance + salary, not entered; the
  run has a `draft → processing → finalized → paid` lifecycle (finalizing locks it; marking
  paid releases every payslip).

## Backend

- Migration `…_create_payroll_tables`: `payroll_periods`, `payslips`, `payslip_earnings`,
  `payslip_deductions`, plus the Company-Setup config `allowance_types` / `deduction_types`
  (ERD §2). All tenant-scoped; money `decimal(12,2)`. Models `PayrollPeriod` / `Payslip`
  (hashid) + line and config models.
- Canonical services in `app/Support/Payroll`: **`PayrollProcessor`** (generates a period's
  payslips from active employees, deriving `days_worked` + overtime from `attendance_records`
  — reusing the DTR) and **`PayrollCalculator`** (pure: basic/overtime pay, and each
  deduction from its `DeductionType.computation` rate/bracket config). Totals contract:
  `gross = basic + overtime + earnings`, `net = gross − deductions`. Statutory deductions
  are proportional to gross; withholding is a bracket on the post-contribution gross.
- Web: `routes/payroll.php`; `PayrollController` (index, show, store, process, finalize,
  markPaid, destroy); `PayrollPeriodResource` / `PayslipResource`; `StorePayrollPeriodRequest`.
- Permissions: new **Payroll** group (`payroll.view` / `.process` / `.release`) in
  `PermissionRegistry`, granted to **HR Manager** in `OrganizationProvisioner`.
- `PayrollSeeder` (after `AttendanceSeeder`): seeds PH statutory config + four recent
  semi-monthly runs (paid / finalized / processing), processed from the seeded attendance.

## Frontend

- New `features/payroll/`: `types.ts`, `routes.ts`, `constants.ts` (peso formatter, status
  styles), `api.ts`, and components — KPI stats, run card, new-run dialog, status badges,
  payslip table (collapsible breakdown), payslip document modal, confirm dialog.
- Pages `payroll/index.tsx` (runs overview) and `payroll/show.tsx` (run detail). The
  sidebar's Payroll entry is now gated by `payroll.view`.

## Docs

- [Payroll module](../modules/payroll.md), [payroll tables](../database/payroll-tables.md);
  ERD §7 marked built.

## Notes

- Verified: `php -l` + `vendor/bin/pint` clean on all PHP; a tinker smoke test processed
  the seeded runs (100 payslips across 4 periods) and confirmed every payslip reconciles
  (`gross = basic + overtime + earnings`, `net = gross − deductions`) with **no negative
  net**; `tsc`, ESLint, Prettier and `npm run build` all green. Pest can't run on this
  machine (no `pdo_sqlite`); migrations ran against Postgres.
- Computation is deliberately simplified/deterministic (monthly salary over a 22-day month)
  — a believable demo, **not** a legally-exact PH payroll engine.
- Deliberately out of scope: `benefit_contributions`, recurring `employee_allowances`, the
  `/setup/payroll` config CRUD, employee self-service payslips, and an assistant capability.
