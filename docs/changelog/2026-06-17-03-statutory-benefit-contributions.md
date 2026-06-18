# 2026-06-17 — Statutory benefit contributions (remittance report)

A follow-up that restores the ERD's `benefit_contributions` table — the half the
Benefits module's first cut left out. The system computed only the **employee** side
of SSS / PhilHealth / Pag-IBIG (as payslip deductions); the **employer** counterpart
(a mandatory company cost) and the monthly **remittance totals** were computed
nowhere. This closes that gap, derived from payroll so it can't drift. Benefits now
has both halves: program enrollments **and** statutory contributions (see updated
[ADR 0011](../decisions/0011-benefits-administration.md)).

## Highlights

- **`/benefits/contributions`** — a remittance report: pick a month, see per-agency
  totals (SSS / PhilHealth / Pag-IBIG with employee + employer share) and a
  per-employee register with a totals footer. A Plans / Contributions tab nav ties it
  to the existing Benefits overview.

## Backend

- **Schema:** `benefit_contributions` (employee, payroll_period, `period` YYYY-MM,
  benefit, employee_share, employer_share, total; unique per run/employee/benefit).
- **Model** `BenefitContribution` + `Employee::benefitContributions()`.
- **`BenefitContributionGenerator`** — derives a run's contributions from its
  payslips: employee share = the statutory payslip deduction; employer share =
  computed from a new `employer` block on the statutory deduction type's
  `computation` (SSS ~9.5%, PhilHealth 50/50, Pag-IBIG matched), on the same gross.
- **Generation is wired into payroll:** runs regenerate contributions on
  process / re-process (`PayrollController`) and on payslip adjust / reset-to-auto
  (`PayslipController`); the `PayrollSeeder` generates them per seeded run and now
  carries the employer-share config.
- **`BenefitContributionController`** — the per-month remittance summary + register,
  gated by `benefits.view`; route `/benefits/contributions`.

## Frontend

- `pages/benefits/contributions.tsx` (month selector, per-agency summary cards, the
  remittance register table) and a `BenefitsTabs` nav shared with the overview. A
  table is used here deliberately — a remittance register is exactly that shape.

## Notes

- Verified: `php -l` + Pint clean; a tinker check generated 75 rows for a run and
  reconciled the employee share against the payslip deduction exactly, with the
  employer SSS share ≈ 2.1× the employee's; backfilled **300 rows across the 4 demo
  runs (2 months)**. `tsc`, ESLint, Prettier and `npm run build` all green.
- After pulling: `php artisan migrate`, then `db:seed --class=PayrollSeeder` (refreshes
  the statutory employer-share config) and re-process runs — or `migrate:fresh --seed`.
