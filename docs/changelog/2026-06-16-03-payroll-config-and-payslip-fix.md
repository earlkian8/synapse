# 2026-06-16 — Payroll configuration (Company Setup) + payslip fix

A follow-up to the Payroll module: the **Company Setup → Payroll Configuration** surface
(deferred in the first cut) is now built, and a payslip-serialisation bug on the run detail
page is fixed.

## Fix

- **`payslips.reduce is not a function`** on `/payroll/{period}` — the nested
  `PayslipResource::collection(...)` serialised to a wrapped `{ data: [...] }` object, so the
  client received a non-array. `PayrollPeriodResource` now resolves the payslips collection
  to a plain array inside the `whenLoaded` callback (matching the rest of the codebase).

## Payroll Configuration (`/setup/payroll`)

- Manage **allowance types** (name + taxable) and **deduction types** (name, kind, mandatory,
  and a flat % rate + optional monthly cap that assembles the `DeductionType.computation`
  config). Full lifecycle: create / edit / archive / restore / permanent delete; each row
  shows its payslip usage count. Bracket-based deductions (withholding tax) are shown
  read-only unless a rate is set.

## Backend

- `Setup\PayrollSetupController` (index) + `Setup\AllowanceTypeController` /
  `Setup\DeductionTypeController` (store / update / destroy / restore / forceDelete);
  `AllowanceTypeRequest` / `DeductionTypeRequest`; `AllowanceTypeResource` /
  `DeductionTypeResource`. `AllowanceType` / `DeductionType` gained `HasHashid` and a
  payslip-line relation (for usage counts). Routes under `setup/payroll/*`.
- New permissions `setup.payroll.view` / `setup.payroll.manage` (Company Setup group),
  granted to **HR Manager**.

## Frontend

- New `features/payroll-config/` (routes, types, allowance & deduction form sheets) and page
  `pages/setup/payroll.tsx` (two managed sections, mirroring the Leave Types setup surface).
  The sidebar's Payroll Configuration entry is gated by `setup.payroll.view`.

## Notes

- Verified: `php -l` + Pint clean; a tinker check confirmed the Setup resources resolve
  (rate/cap exposed for flat-rate deductions, bracket shown read-only, usage counts correct);
  `tsc`, ESLint, Prettier and `npm run build` all green. Permissions re-synced via
  `RolePermissionSeeder`. Demo data already in place from `PayrollSeeder` (4 runs · 100
  payslips · statutory config).
