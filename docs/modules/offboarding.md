# Offboarding

The structured exit of a departing employee — the mirror image of
[Onboarding](./onboarding.md). An offboarding **case** opens a **clearance**
checklist routed to the responsible departments; finalising it separates the
employee. The *why* is in [ADR 0016](../decisions/0016-offboarding-and-clearance.md);
this is the *how*. Everything is tenant-scoped (ADR 0005). Data model is ERD §9.

> Status: **Active** · Route prefix: `/offboarding`
> Sidebar: Offboarding (gated by `offboarding.view`)

## Where it sits in the life cycle

```
hire ─▶ Onboarding ─▶ … productive tenure … ─▶ Offboarding ─▶ separated
        └ ADR 0007 ┘                            └ this module ┘
```

A case is started **manually** by HR for a leaving employee. Completing it marks the
employee `resigned` / `terminated` — the canonical way they leave `active`.

## Surfaces

Laid out like the Workforce modules and Onboarding (header · compact stat tiles ·
toolbar · table · pagination), from the shared table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md),
[ADR 0048](../decisions/0048-talent-acquisition-and-offboarding-join-the-table-kit.md)):

- **`/offboarding`**: everyone leaving, as **one table**.
  - Four stat tiles: in offboarding, flagged clearances, leaving in 14 days, completed
    this month.
  - The toolbar has search, exit type, department and status filters (*Active* by
    default), Reset, *Export* (with the current filters) and *Start offboarding*.
  - Each row shows the employee, department and position, exit type, status, clearance
    progress (cleared / total, with the flag count) and last working day. A last day an
    exit still in flight has passed shows in red, and a completed exit shows *Done …*.
  - Columns sort by employee, type, status, clearance and last day, and the table is
    paged in the browser.
  - A row opens the case. Its menu opens the clearance, exports its sheet, completes the
    exit (confirmed, naming any outstanding items), reopens it, cancels it (confirmed)
    or deletes it (confirmed).
- **`/offboarding/{case}`**: the **case**.
  - The employee header has status and type badges, *Export sheet*, *Add item* and the
    lifecycle menu (complete, reopen, add from template, clear all pending, edit
    details, cancel, delete).
  - A clearance summary shows the signed-off count, the derived clearance status, the
    notice and last day, the template and flags.
  - The **clearance checklist is a table**. Each department that signs items off has a
    header row with its cleared count and *Clear all (n)*, and Unassigned comes last.
    Each item row has a tick-box, its status, who signed it off and when, and remarks
    (red when flagged). Its menu flags or unflags the item, edits it or deletes it, and
    for managers the row opens the item to edit.
- **Employee detail → Offboarding tab**: a read-only summary of the employee's exit
  (type, status, clearance progress, last day), linking to the case.

## Data model

`offboarding_cases`, `clearance_items` — see the
[schema doc](../database/offboarding-tables.md). Highlights:

- A **case** is one employee's exit (`unique(employee_id)`), with a `type`
  (`resignation | termination | retirement | end_of_contract`), the notice /
  last-working-day dates, a reason, and a lifecycle
  (`initiated → clearance → completed`, or `cancelled`).
- A **clearance item** is one sign-off: a label, an owning `department_id`, a status
  (`pending → cleared`, or `flagged`), the `cleared_by` user + `cleared_at`, and
  `remarks` (the sign-off note / flag reason).

## Backend

- Controllers (`app/Http/Controllers/Offboarding/`): `OffboardingCaseController`
  (index / show / store / update / status / destroy), `ClearanceItemController`
  (store / update / toggle / destroy).
- **`App\Support\OffboardingProvisioner`** — the connective tissue (the
  `OnboardingProvisioner` analogue). `start()` opens a case and instantiates the
  **standard clearance checklist**, routing each item to its department (IT / Finance /
  HR by code, or the employee's own department); idempotent per employee. It is also
  the single source of truth for the **derived clearance status** (`clearanceStatus()`).
- Requests under `app/Http/Requests/Offboarding/`; resources `OffboardingCaseResource`
  (with a derived `clearance` summary) + `ClearanceItemResource`; queries
  `OffboardingCasesIndexQuery` (filtered, with clearance counts — no pagination, the
  board is card-based) and `OffboardingStatistics`.
- `routes/offboarding.php` (literal-prefixed `clearance/…` routes precede the `{case}`
  wildcard). Every route is permission-gated. Cases are addressed by **hashid**
  (`App\Support\Hashid`, via `HasHashid`); items by numeric id (sub-resources).
- Mutations are activity-logged (`logName: 'offboarding'`).

### Clearance status & lifecycle

- **Derived clearance status** — `OffboardingProvisioner::clearanceStatus()` returns
  `pending` (untouched) / `in_progress` (some signed off) / `cleared` (all signed off)
  from the item counts. Never stored, so it cannot drift (the onboarding-progress norm).
  `flagged` items keep a case off `cleared`.
- **Auto-advance** — an `initiated` case **nudges to `clearance`** on the first sign-off
  or flag, so the board reflects activity without a manual status change (exactly as
  onboarding nudges `pending → in_progress`).
- **Completion bridge** — completing the exit stamps `completed_at` and transitions the
  employee's `employment_status` to match the type; reopening / cancelling returns them
  to `active`. The complete action confirms the change (and warns of any pending items).

## Frontend

`features/offboarding/` holds the types, routes, constants (status, type and clearance
meta) and the filter hook, and these components:

- `offboarding-stats` (stat tiles);
- `case-table` and `case-row-actions`;
- status and type badges, and the progress bar;
- `clearance-table`, grouped by department;
- a confirm dialog;
- four forms, all **centred modals** on the shared `components/modal.tsx` shell with
  `FormField` / `FormSelect`, the same as Onboarding: `initiate-offboarding-dialog`,
  `clearance-item-form-dialog`, `case-settings-dialog` and `apply-program-dialog`.

Pages are `pages/offboarding/index.tsx` and `case.tsx`. The clearance templates'
screen (`programs-manager`, `program-form-sheet`) belongs to Company Setup. The sidebar
**Offboarding** link is gated on `offboarding.view`.

## Permissions

`offboarding.view` (overview + detail) and `offboarding.manage` (start exits, manage
clearance, lifecycle). Built-in **HR Manager** gets both; Super Admin / Administrator
get them via the all-permissions grant.

## Seeding

`OffboardingSeeder` (in `DatabaseSeeder`) seeds 5 exits across the
lifecycle — completed (employee separated), in clearance, in clearance with a flagged
item, just initiated, and cancelled — each with the standard 10-item clearance
checklist, on active employees. Idempotent: it seeds when no exit is in flight — the
completed cases the workforce history leaves behind (`WorkforceHistorySeeder`, about
230 past departures for [model graduation](./model-graduation.md#demo-history)) are the
past, not the board's demo cases.

## Out of scope (this cut)

Auto exit-interview surveys, document generation (clearance form / COE PDF), a
self-service employee resignation request, final-pay computation from the case, and an
assistant capability — matching the Training / Awards / Events precedent of shipping the
operational core first.
