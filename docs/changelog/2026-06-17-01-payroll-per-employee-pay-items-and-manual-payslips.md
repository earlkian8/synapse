# 2026-06-17 — Per-employee pay items + manual payslip editing

Payroll stops being automatic-only. Two gaps close: (A) Company-Setup allowance /
deduction types now drive payslips through **recurring per-employee assignments**
(the engine no longer hardcodes Rice + Meal for tenured staff only), and (B) HR can
**hand-edit a generated payslip's line items** with a correct recomputed net.

## Highlights

- **Per-employee allowances & deductions** — assigned on the **Employee detail →
  Compensation** tab (type from the Setup catalogue + per-employee amount, toggle
  active, remove). A type like "Transportation Allowance" now appears once assigned,
  and contractual / part-time staff can receive allowances. Recurring deductions
  (e.g. a loan) stack on top of the statutory ones.
- **Manual payslip editing** — open a payslip on an open run and **Edit lines**:
  add / remove / relabel earning and deduction lines with a live net preview; basic
  & overtime pay stay auto. Saving flags it **Adjusted**; a re-process preserves it,
  and **Reset to auto** regenerates it. Blocked once the run is finalized / paid.

## Backend

- **Schema:** `employee_allowances` + `employee_deductions` (tenant-scoped, typed,
  per-employee `amount` + `is_active`); `payslips.is_adjusted`.
- **Models:** `EmployeeAllowance` / `EmployeeDeduction`; `Employee::allowances()` /
  `recurringDeductions()`; `Payslip.is_adjusted`.
- **Engine:** `PayrollProcessor` reads each employee's **active** allowances →
  earning lines and deductions → deduction lines (removing the hardcoded defaults),
  **skips `is_adjusted` payslips** on re-process, and gained `buildFor()` to
  regenerate a single payslip. `PayrollCalculator::totals()` is the one source of
  truth for the totals contract, reused by the processor and manual edits.
- **Controllers / requests:** `Employee\EmployeeAllowanceController` /
  `EmployeeDeductionController` (store / update / destroy) +
  `EmployeeAllowanceRequest` / `EmployeeDeductionRequest`;
  `Payroll\PayslipController` (`update`, `resetToAuto`) + `UpdatePayslipRequest`.
  Resources `EmployeeAllowanceResource` / `EmployeeDeductionResource`; the employee
  show endpoint attaches the allowance/deduction catalogues; `PayslipResource`
  exposes `is_adjusted` + line type ids. Routes under `employees/{employee}/
  allowances|deductions` and `payroll/payslips/{payslip}`.
- **Permission** `payroll.adjust` (per-employee items + payslip editing), granted to
  **HR Manager**. Activity logged under `payroll`.
- **Seeder:** `PayrollSeeder` seeds per-employee allowances (Rice/Meal for tenured,
  Transportation for some, incl. non-tenured) and a "Company Loan" deduction type +
  a few loans, so demo payslips keep reconciling from the new source.

## Frontend

- **Employees:** a **Compensation** tab in `employee-detail-sheet.tsx` managing the
  two item lists (Setup-type picker + amount, active switch, remove); types extended;
  routes added.
- **Payroll:** `payslip-document-modal.tsx` gains an edit mode (editable line rows,
  live net preview, save / cancel, **Adjusted** badge, **Reset to auto**); the payslip
  table shows the Adjusted badge; `show.tsx` derives the open payslip by id so it
  stays live after an edit. `api.ts` / `routes.ts` / `types.ts` updated.

## Notes

- Verified: `php -l` + Pint clean across all changed files; a **transactional tinker
  smoke test** (rolled back) processed a run with **0 reconcile mismatches**, confirmed
  allowances drive payslips (9 Transportation lines incl. non-tenured), loans apply,
  `buildFor()` regenerates a single payslip, and **re-process preserves an adjusted
  payslip**. `tsc`, ESLint, Prettier and `npm run build` all green. (Pest can't run
  locally — no `pdo_sqlite`.)
- After pulling: run `php artisan migrate` (additive). A fresh `migrate:fresh --seed`
  re-syncs `payroll.adjust` to HR Manager and reprocesses the demo runs from the new
  per-employee source.
