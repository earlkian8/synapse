# Payroll Enhancement Plan — per-employee pay items + manual payslip editing

> Status: **PLAN ONLY — not yet built.** Scope approved: **A + B**, with **per-employee
> allowance amounts**. This document is the spec to review before coding.

## 1. Why (the gap we're closing)

Payroll today is **automatic-only**. A payslip's amount is computed, never entered:

```
basic_pay = basic_salary ÷ 22 × days_worked     (from the Employee + Attendance)
+ overtime_pay                                    (from Attendance)
+ allowances                                      (HARDCODED: Rice ₱2,000 + Meal ₱1,500, regular/probationary only)
− statutory deductions                            (mandatory deduction_types, everyone)
= net_pay
```

Verified problems:
- **Allowance config is decorative.** The engine hardcodes two allowances by name, so a
  Setup allowance type like "Transportation Allowance" appears on **0 payslips**, and
  **contractual/part-time staff get no allowances**.
- **No per-employee money.** There's nowhere to say "Maria gets ₱3,000 transport" or
  "deduct Juan's ₱1,500/mo loan." (The ERD's `EMPLOYEE_ALLOWANCE` was deferred.)
- **No manual control.** You can't add a one-off bonus, a loan, a correction, or override a
  single payslip. Everything is recomputed from scratch.

**Goal:** (A) tie pay items to individual employees so Setup config actually drives
payslips, and (B) let HR manually adjust an individual payslip.

---

## 2. A — Per-employee recurring pay items

### 2.1 Data model

**`employee_allowances`** (ERD §3 — `EMPLOYEE_ALLOWANCE`, finally built):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `allowance_type_id` | FK → allowance_types | Restrict/null on delete. |
| `amount` | decimal(12,2) | The per-employee peso amount. |
| `is_active` | boolean | Inactive items are ignored by a run. |
| timestamps | | |

**`employee_deductions`** (⚠️ **NOT in the current ERD** — a proposed extension for recurring
per-employee deductions such as loans; mirror of the above):

| Column | Type | Notes |
| --- | --- | --- |
| `id` / `organization_id` / `employee_id` | | as above |
| `deduction_type_id` | FK → deduction_types | The kind (e.g. a `loan`). |
| `amount` | decimal(12,2) | Per-pay-period amount. |
| `is_active` | boolean | |

> **ERD decision needed:** add `EMPLOYEE_DEDUCTION` to `docs/database/erd.md` §3/§7. If you'd
> rather keep recurring loans out of the ERD for now, drop this table and handle loans purely
> through manual payslip editing (B). Recommended: add it — it's the symmetric, clean answer.

### 2.2 Backend

- **Models** `EmployeeAllowance`, `EmployeeDeduction` (`BelongsToOrganization`); relations on
  `Employee`: `allowances()`, `recurringDeductions()` (hasMany).
- **`PayrollProcessor` change** (the core fix): replace `defaultEarnings()` hardcoding with
  reads of the employee's **active `employee_allowances`** → earning lines (typed +
  per-employee amount). Add the employee's **active `employee_deductions`** → deduction
  lines, on top of the mandatory statutory `deduction_types` (unchanged). Statutory logic
  stays as-is.
- **Management surface** — these live on the **Employee** (that's the "connects to the
  employee" part). Extend the Employee detail with an **"Allowances & Deductions"** section:
  - `EmployeeAllowanceController` / `EmployeeDeductionController` (or one
    `EmployeeCompensationController`) with `store` / `update` / `destroy`, nested under the
    employee. Thin; FormRequests for validation (amount ≥ 0, type exists in tenant).
  - Reuse `employees.*` permissions (e.g. `employees.update` / `employees.manage-documents`)
    or add `payroll.assign` — **decision below**.
- **Resources:** extend `EmployeeResource` (or a dedicated resource) to expose the lists.

### 2.3 Frontend

- Employee detail sheet → new **"Allowances & Deductions"** block: list current items
  (type + amount + active), add/edit/remove via a small form (type `Select` from
  `allowance_types` / `deduction_types`, peso amount). Mirrors the existing employee
  sub-record sections (certifications/documents).
- The Setup → Payroll allowance/deduction types become the **catalogue** these pickers read,
  so the config is finally meaningful end-to-end.

### 2.4 Seeder

- `PayrollSeeder` (or `OrganizationSeeder`): seed a few `employee_allowances` (e.g. Rice +
  Meal for regular staff, Transport for some) so demo payslips keep showing allowances after
  the hardcoding is removed. Re-process the seeded runs so figures reflect the new source.

---

## 3. B — Manual payslip editing

### 3.1 Data model

- Add **`is_adjusted`** (boolean, default false) to **`payslips`**. Set true once a payslip's
  lines are hand-edited.
- No other schema change: a manual edit = inserting/updating/deleting `payslip_earnings` /
  `payslip_deductions` rows (label + amount, optional type), then recomputing totals.
  `basic_pay` / `overtime_pay` stay auto (their own columns); manual **earning** lines (e.g.
  "Performance Bonus") raise `total_earnings`→`gross`, manual **deduction** lines (e.g.
  "Cash Advance") raise `total_deductions`.

### 3.2 The re-process tension (key decision)

Re-processing a run currently **deletes and regenerates every payslip**, which would wipe
manual edits. Plan:

- **Recommended:** re-process **skips `is_adjusted` payslips** (leaves them untouched) and
  shows an "Adjusted" badge on those rows, plus a **"Reset to auto"** action that
  regenerates just that payslip and clears the flag.
- *Alternative (more complex):* tag each line `source = auto|manual`; re-process regenerates
  `auto` lines and preserves `manual` ones. More flexible, more moving parts — not
  recommended for this cut.

### 3.3 Backend

- **`PayslipController`** (new) with:
  - `update(Payslip)` — replace the manual lines from the request, recompute totals via a
    new `PayrollCalculator::totals()` helper, set `is_adjusted = true`. Blocked when the run
    is `finalized`/`paid`.
  - `resetToAuto(Payslip)` — re-run the processor for that one employee, clear `is_adjusted`.
- **FormRequest** `UpdatePayslipRequest` — validates `earnings[]` / `deductions[]`
  (label required, amount ≥ 0, optional type id in tenant).
- **`PayrollProcessor`** — extract a `buildFor(period, employee)` so a single payslip can be
  (re)generated without rebuilding the whole run.
- Routes under `payroll/payslips/{payslip}` (gate: `payroll.process`, or new `payroll.adjust`).

### 3.4 Frontend

- The **payslip document modal** gains an **Edit mode** (when run is draft/processing and the
  user can adjust): editable earning/deduction line rows (label + amount, add/remove), a live
  net preview, Save / Cancel. An **"Adjusted"** badge + **"Reset to auto"** when applicable.
- This is the "create a payslip with an amount in it" you were looking for — you open the
  generated payslip and set the lines.

---

## 4. Cross-cutting

- **Permissions:** simplest is to reuse `payroll.process` for payslip adjustments and
  `employees.update` for per-employee items. Cleaner is a dedicated **`payroll.adjust`** —
  **decision needed** (see §6). Add to `PermissionRegistry` + grant HR Manager in
  `OrganizationProvisioner` if we add it.
- **Activity logging:** log per-employee item changes and payslip adjustments
  (`logName: 'payroll'`).
- **Multi-tenancy / driver-aware / validation:** follow `IMPLEMENTATION-METHOD.txt` — new
  tables get `organization_id` + `BelongsToOrganization`; all writes via FormRequests; works
  on Postgres + SQLite.
- **Totals contract unchanged:** `gross = basic + overtime + total_earnings`,
  `net = gross − total_deductions`; recompute always server-side.

## 5. Docs / changelog / memory (part of the change)

- `docs/database/payroll-tables.md` + `employees-tables.md`: add the new tables.
- `docs/database/erd.md`: add `EMPLOYEE_ALLOWANCE` wiring (and `EMPLOYEE_DEDUCTION` if
  approved).
- `docs/modules/payroll.md` + `employees.md`: document per-employee items + manual editing.
- Dated changelog under `docs/changelog/`; update the `payroll-module` memory.

## 6. Open decisions before coding

1. **`employee_deductions` table** — add it (recommended, symmetric) or handle recurring loans
   only via manual payslip editing?
2. **Permission** — reuse `payroll.process` / `employees.update`, or add `payroll.adjust`?
3. **Where to manage per-employee items** — on the **Employee** detail (recommended, matches
   ERD §3) vs a section inside the Payroll module.

## 7. Suggested build order

1. Migration: `employee_allowances` (+ `employee_deductions` if approved) + `payslips.is_adjusted`.
2. Models + relations; `PayrollProcessor` reads per-employee items (remove hardcoding);
   `PayrollCalculator::totals()` + `buildFor()`.
3. Per-employee management UI on the Employee detail (A).
4. Manual payslip edit mode + `PayslipController` + re-process skip-adjusted (B).
5. Seeder updates; re-process demo runs.
6. Docs + changelog + memory; verify (`php -l`, Pint, tinker reconcile, `tsc`, ESLint,
   Prettier, build).

## 8. Definition of done

- Setup allowance/deduction types **demonstrably** drive payslips via per-employee
  assignments (Transportation Allowance > 0 payslips; contractual staff can have allowances).
- HR can open a generated payslip and **add/adjust line items with a correct recomputed net**.
- Re-processing **preserves** adjusted payslips; "Reset to auto" works.
- Every payslip still reconciles; no negative net; all checks green.
