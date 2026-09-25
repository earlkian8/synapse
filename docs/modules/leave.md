# Leave Management

Employees' **time off**: file requests, approve or reject them, and track each person's
balance. The *why* (derived balances, approval lifecycle, inbox UI) is in
[ADR 0009](../decisions/0009-leave-management.md); this is the *how*. Everything is
tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/leave` · Sidebar: Workforce → Leave Management
> (the **leave types** are configured under Company Setup → Leave Types, `/setup/leave-types`)

## Surfaces

- **`/leave`** — the **approvals inbox**: stats, a status-tabbed queue (Pending /
  Approved / Upcoming / Rejected / Cancelled / All), and filters (search, type,
  department). Each row shows the employee, type, dates and day count; pending rows have
  inline **Approve / Reject**. Opening a row reveals a **review drawer** with the full
  request, the **balance impact** for that type/year, and the actions
  (approve/reject with a note, edit, cancel, delete).
- **`/leave/balances`** — per-employee balances for a chosen **year**: each employee's
  entitlement, used and remaining days per type, with an **adjust drawer** to set
  entitlements.
- **`/setup/leave-types`** — Company Setup: the kinds of leave (a card grid), each with a
  colour, default annual entitlement and policy flags (paid, half-day, requires-approval,
  active). Archive / restore / permanent-delete.

The inbox is a **queue**, not a data table: leave is action-oriented (clear the pending
list). Balances are a **list with chips**, not a wide employee×type matrix.

## Data model

`leave_types`, `leave_balances`, `leave_requests` — see the
[schema reference](../database/leave-tables.md). Highlights:

- A **leave type** has a `code` (**unique per tenant**, ignoring archived rows), a
  `default_days` annual entitlement, a colour, and the flags `is_paid`,
  `allow_half_day`, `requires_approval`, `is_active`. Soft-deletes.
- A **balance** stores only the **entitlement** for an employee + type + year. **Used**
  and **pending** are *derived* from requests, never stored.
- A **request** has a date range, a server-computed `days` (working days, half-day
  aware), a `status` (`pending|approved|rejected|cancelled`), and `filed_by` /
  `reviewed_by` (users).

`LeaveType` and `LeaveRequest` use `HasHashid` (URLs never expose integer ids).

## Backend

- Controllers: `Leave\LeaveRequestController` (index / show / store / update / review /
  cancel / destroy), `Leave\LeaveBalanceController` (index / store), and
  `Setup\LeaveTypeController` (index / store / update / destroy=archive / restore /
  forceDelete).
- `LeaveCalculator` (support) computes chargeable **working days** (Mon–Fri, **excluding
  non-working holidays** supplied by `HolidayCalendar`; a single day may be a 0.5 half day).
  See [Work Schedule & Holidays](./work-schedule-holidays.md).
- `LeaveBalanceService` (query) composes balances: entitlement (stored row or type
  default) minus **used** (approved) with **pending** alongside, via grouped aggregates.
  `LeaveRequestsIndexQuery` filters the inbox; `LeaveStatistics` powers the cards.
- Requests `Leave\StoreLeaveRequestRequest` (half-day must be a single day of a type that
  allows it; `days` recomputed server-side), `Leave\StoreLeaveBalanceRequest`,
  `Setup\LeaveTypeRequest` (per-tenant unique code).
- Routes: `routes/leave.php` (`leave.*`) and the `leave-types.*` block in
  `routes/setup.php`. Requests / types are addressed by **hashid**; balances POST by
  body. Mutations are activity-logged (`logName: 'leave'` / `'company-setup'`).
- **Notifications:** a new pending request notifies the `hr-manager` role; an approval /
  rejection notifies the employee's linked user (if any).

## Frontend

- `features/leave/` — the inbox and balances: types, routes, constants, the filters hook,
  and components (stats, the status-tabbed toolbar, request row, the **file-leave**,
  **review-request** and **adjust-balance modals**, balances toolbar/cards, and a shared
  Requests/Balances nav). Pages: `pages/leave/{index,balances}`.
- All three open **centred**, on the shared `components/modal.tsx` shell the rest of the
  app uses, and the module reuses the shared `components/confirm-dialog.tsx` rather than
  carrying its own copy.
- The **review modal** is laid out the way an approver reads a request: the person in the
  header, the ask (type, dates, days charged) in a strip that stays in view, and the
  **balance meter** as the body's first and largest thing — the only part of the decision
  that is a number rather than a judgement. The reason and the review note are always
  drawn, stating plainly when nothing was written, so the layout does not change shape
  with how much anybody typed.
- The **file-leave modal** pairs its four decisions two-up (who and what kind, from and
  to) over a live estimate of the working days the request will actually charge.
- `features/leave-types/` — the Company Setup catalogue: a **card** per type, a
  **form sheet** (colour picker + policy switches), confirm dialog. Page:
  `pages/setup/leave-types`.
- Sidebar: **Workforce → Leave Management** gated on `leave.view`;
  **Company Setup → Leave Types** gated on `setup.leave-types.view`.

## The agentic assistant

`App\Services\Assistant\Modules\LeaveModule` files, reviews and cancels requests in
conversation. It also contributes to the **retrieved brief** a turn about a person is
answered from — this year's entitlements (through `LeaveBalanceService`, so a figure
quoted in chat is the figure the balances screen shows), the last five requests, and how
many are waiting on a decision. Both paths need `leave.view`; see
[ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md).

## Permissions

`leave.view`, `leave.request` (file / edit / cancel), `leave.manage`
(approve / reject / delete / set balances); `setup.leave-types.view`,
`setup.leave-types.manage`. All seeded to Super Admin / Administrator and HR Manager.

## Tests

- `tests/Unit/LeaveCalculatorTest.php` — DB-free working-day / half-day arithmetic.
- `tests/Feature/Leave/LeaveTest.php` — inbox render, filing (+ day computation),
  auto-approval, the half-day guard, approve / reject (and the already-reviewed guard),
  cancel / delete, balances render, setting entitlements, **derived** used/pending,
  authorization matrix, tenant isolation.
- `tests/Feature/Setup/LeaveTypeTest.php` — render, create (code upper-cased) +
  validation, per-tenant duplicate rejection + cross-org reuse, update,
  archive/restore/force-delete (+ force blocked when requests exist), authorization,
  isolation.

(The Feature suite needs `pdo_sqlite` / CI; the calculator unit tests run anywhere, and
every controller path was validated against live Postgres.)
