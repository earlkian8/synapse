# Remove the Payroll and Benefits modules

Removes the **Payroll** and **Benefits** modules entirely — pay-run processing and
benefits administration are downstream of core HR and out of scope for this
HR-management system. Everything connected to them goes too: the payroll/benefits
Company-Setup config, the recurring per-employee pay items, and the statutory
contributions. Employee **compensation stays on the employee record** (so the
machine-learning `salary` feature keeps working). See
[ADR 0019](../decisions/0019-remove-payroll-and-benefits.md).

## Removed

- **Payroll** — `payroll_periods`, `payslips`, `payslip_earnings`, `payslip_deductions`;
  `PayrollProcessor` / `PayrollCalculator`; the `allowance_types` / `deduction_types`
  config; and the recurring per-employee `employee_allowances` / `employee_deductions`
  pay items.
- **Benefits** — `benefit_plans`, `benefit_enrollments`, statutory `benefit_contributions`
  and `BenefitContributionGenerator`.
- All their migrations, models, controllers, form requests, resources, routes, seeders
  (`PayrollSeeder`, `BenefitSeeder`), Inertia pages and feature folders
  (`features/payroll`, `payroll-config`, `benefits`, `benefits-config`).
- Permissions `payroll.*`, `benefits.*`, `setup.payroll.*`, `setup.benefits.*` (dropped
  from `PermissionRegistry` and the built-in HR-Manager grant); the four sidebar entries.

## Kept (deliberately)

- **Employee compensation as HR master data** — `employees.basic_salary`, bank details
  and government-ID numbers stay on the employee record and in the employee form +
  read-only profile display. The ML **`salary` feature**
  ([ADR 0018](../decisions/0018-performance-forecasting.md)) reads `basic_salary`, so
  predictive analytics is unaffected.
- **Attendance** — a standalone DTR module; it was a payroll *source*, not part of it.

## Changed

- **Shared `ConfirmDialog` relocated** from `features/payroll/components` to
  `@/components/confirm-dialog` (nine unrelated pages imported it); all imports updated.
- **Employee detail drawer** — the **Compensation** (pay-items) and **Benefits** tabs
  were removed; the read-only salary/IDs section in the Profile tab remains.
  `EmployeeController::show` no longer eager-loads pay-items/enrollments or attaches the
  allowance/deduction catalogues; `EmployeeResource` drops those keys.
- **Docs** — `modules/payroll.md`, `modules/benefits.md`, `database/payroll-tables.md`,
  `database/benefits-tables.md` deleted; ERD §7 marked **Removed** (proposed shape kept
  for reference); README, `employees` module + table docs updated. ADR 0011 kept as a
  **tombstone** pointing to the new **ADR 0019**.
- **Seeders** — `SystemSeeder`'s payroll/benefits activity-log + notification demo
  entries removed; `DatabaseSeeder` / `MockSeeder` no longer call the deleted seeders.

## Notes

- Verified: `php -l` + Pint (`passed`) on every changed PHP file; a full
  `php artisan migrate:fresh --seed` ran clean on Postgres (no payroll/benefits). A
  tinker forecaster smoke test (stubbed `MlClient`) confirmed **ML still scores all
  active employees with the `salary` feature intact** post-removal. Frontend: `tsc`,
  ESLint (project-wide) and Prettier green; `vite build` succeeds with no payroll/benefits
  chunks (the `employees` chunk shrank). Pest was **not** run locally (no `pdo_sqlite`).
- No data migration is provided — this is a clean removal; existing payroll/benefits rows
  are dropped on the next `migrate:fresh`. The permissions table re-syncs without the
  removed permissions on the next provision/seed.
