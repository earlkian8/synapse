# Benefits Administration

The organisation's benefit program has two halves: the **plans** it offers (HMO,
insurance, retirement, wellness) and **who is enrolled** in each, plus the monthly
**statutory contributions** (SSS / PhilHealth / Pag-IBIG) it must remit. Plans are
configured in Company Setup; contributions are derived from payroll. Data model is
ERD §7 (the benefits side); everything is tenant-scoped (ADR 0005). See
[ADR 0011](../decisions/0011-benefits-administration.md) for why both halves exist.

> Status: **Active** · Route prefix: `/benefits` · Config: `/setup/benefits`
> Sidebar: Workforce → Benefits Administration (gated by `benefits.view`);
> Company Setup → Benefits Configuration (gated by `setup.benefits.view`)

## Surfaces

- **`/benefits`** — the **overview**: a KPI bar (active plans, employees covered,
  employer monthly cost, employee monthly cost) and a grid of **plan cards grouped
  by category**, each showing the provider, the employee / employer share per
  period, and the live enrollee count. A card opens the plan's roster.
- **`/benefits/{plan}`** — a **plan's roster**: a header with the plan's category,
  provider, description, cost block and active-enrollee count, then the list of
  enrolled employees (status, member reference, enrolled date). HR can **enroll an
  employee**, **edit** an enrollment (status / reference / dates / notes), or
  **remove** one.
- **`/benefits/contributions`** — the **statutory remittance report**: pick a
  remittance month, see per-agency totals (SSS / PhilHealth / Pag-IBIG, employee +
  employer share) and a per-employee register. Figures are **derived from processed
  payroll runs** — the employee share is the payslip's statutory deduction, the
  employer share is the company counterpart (computed). Regenerated whenever a run is
  processed / re-processed or a payslip is adjusted.
- **Employee detail → Benefits tab** — a read-only summary of an employee's plan
  enrollments (managed from this module, not the employee record).

## Cost rollups

A plan carries an `employee_cost` and `employer_cost` for its `frequency`. The
overview KPIs normalise these to a **monthly equivalent** (`quarterly ÷ 3`,
`annual ÷ 12`; `one_time` is excluded from recurring cost) and multiply by each
plan's **active** enrollee count. "Employees covered" counts distinct employees with
at least one active enrollment.

## Configuration (`/setup/benefits`)

Company Setup → **Benefits Configuration** manages the plan catalogue: name,
category, provider, description, the employee / employer cost, frequency, and an
active flag. Full lifecycle: create / edit / archive (soft delete) / restore /
permanent delete; each row shows its enrollee count. A plan with enrollments cannot
be permanently deleted (archive instead).

## Permissions

`benefits.view` (overview & rosters), `benefits.manage` (enroll / edit / remove
enrollments); `setup.benefits.view` / `setup.benefits.manage` (the configuration
surface). Built-in **HR Manager** gets all of them.

## Out of scope (this cut)

Benefit costs are **not** auto-pushed into payroll deductions (a per-employee
deduction can be added in Payroll if needed), dependents/beneficiaries are not
modelled, and there is no employee self-service or assistant capability yet.
