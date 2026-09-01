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

- **`/offboarding`** — the overview **board**: a KPI bar (in offboarding, flagged
  clearances, leaving in 14 days, completed this month), search / type / department /
  status filters, and a card per exit with the exit type, clearance progress, last
  working day and flag count. A card opens the case. (A board of people-cards, like
  Onboarding — the unit of work is *a person being offboarded*.)
- **`/offboarding/{case}`** — the **case**: the employee header (status + type badges),
  a clearance summary (signed-off count, derived clearance status, last working day,
  flags) and the **clearance checklist grouped by department**. HR can clear / flag
  items, edit/assign them, add ad-hoc items, edit the exit details, and
  complete / cancel / reopen the exit.
- **Employee detail → Offboarding tab** — a read-only summary of the employee's exit
  (type, status, clearance progress, last day) linking to the case.

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

`features/offboarding/` — types, routes, constants (status / type / clearance meta),
the board filter hook, and components: stats, toolbar, **case card**, status & type
badges, progress bar, **initiate-offboarding sheet**, **clearance checklist** (grouped
by department) + **item row** + **item form sheet**, **case settings sheet**, and a
confirm dialog. Pages: `pages/offboarding/index.tsx`, `case.tsx`. The sidebar
**Offboarding** link is gated on `offboarding.view`.

## Permissions

`offboarding.view` (overview + detail) and `offboarding.manage` (start exits, manage
clearance, lifecycle). Built-in **HR Manager** gets both; Super Admin / Administrator
get them via the all-permissions grant.

## Seeding

`OffboardingSeeder` (in `DatabaseSeeder` + `MockSeeder`) seeds 5 exits across the
lifecycle — completed (employee separated), in clearance, in clearance with a flagged
item, just initiated, and cancelled — each with the standard 10-item clearance
checklist. Idempotent (only seeds when no cases exist).

## Out of scope (this cut)

Auto exit-interview surveys, document generation (clearance form / COE PDF), a
self-service employee resignation request, final-pay computation from the case, and an
assistant capability — matching the Training / Awards / Events precedent of shipping the
operational core first.
