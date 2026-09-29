# Leave Management

Employees' **time off**: file requests, approve or reject them, and track each person's
balance. The *why* (derived balances, approval lifecycle, inbox UI) is in
[ADR 0009](../decisions/0009-leave-management.md); this is the *how*. Everything is
tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/leave` · Sidebar: Workforce → Leave Management
> (the **leave types** are configured under Company Setup → Leave Types, `/setup/leave-types`)

## Surfaces

- **`/leave`** — the **approvals inbox**: stat tiles and a table of requests (employee,
  leave type, dates, days, status, filed). A **status filter** (Pending by default, then
  Approved / Upcoming / Rejected / Cancelled / All statuses) sits beside search, type and
  department. Pending rows have inline **Approve / Reject**. Opening a row reveals a
  **review modal** with the full
  request, the **balance impact** for that type/year, and the actions
  (approve/reject with a note, edit, cancel, delete).
- **`/leave/balances`** — per-employee balances for a chosen **year**, as a table: one
  column per leave type showing remaining of entitled days with a meter, and an
  **Adjust** button per row that opens the entitlement modal.
- **`/setup/leave-types`** — Company Setup: the kinds of leave (a card grid), each with a
  colour, default annual entitlement and policy flags (paid, half-day, requires-approval,
  active). Archive / restore / permanent-delete.

Both pages use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)). The inbox
is still a queue: it opens on Pending, and approving stays one click on the row.

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
  and components (stats, the toolbar, `leave-requests-table`, the **file-leave**,
  **review-request** and **adjust-balance modals**, balances toolbar and
  `balances-table`, and a shared Requests/Balances nav). Pages: `pages/leave/{index,balances}`.
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

### Self-service and balances (ADR 0059)

- **Your own leave, unless you manage leave.** `App\Support\Leave\LeaveAccess`: without
  `leave.manage`, a user files, edits and cancels only their own leave. The web request
  (`StoreLeaveRequestRequest`), the edit and cancel actions, and the assistant all ask it.
  The web routes gated on `leave.request` used to accept any employee — a member of staff
  could file (and auto-approve) or cancel a colleague's leave; the phone app was already
  confined ([ADR 0059](../decisions/0059-assistant-covers-the-whole-system.md)).
- The capability is offered to `leave.request` holders too: "me" means yourself, and
  without `leave.view` a list shows only your own requests.
- **`get_leave_balances`** — entitled, used, pending and remaining by type for a year;
  your own, or anyone's with `leave.view`.
- **`set_leave_entitlement`** (`leave.manage`, waits for Confirm) goes through
  `App\Support\Leave\LeaveEntitlements`, which the balances screen now uses; the card
  says what would be left.
- **Approving or rejecting** goes through `App\Support\Leave\LeaveReview`, which the
  inbox's review action and `review_leave_request` both use: the same status change,
  audit line (" via assistant" in chat) and message to the employee, and the note is held
  to `ReviewLeaveRequestRequest::rulesFor()` either way. Chat used to carry its own copy,
  with an unchecked note.
- People are resolved exactly — an ambiguous name acts on nobody.

### Leave types in the assistant

`App\Services\Assistant\Modules\LeaveTypesModule` keeps the catalogue
([ADR 0055](../decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md)).
Every write goes through **`App\Support\Setup\LeaveTypeWorkflow`**, which the Leave
Types screen uses too (`LeaveTypeException` for the restore code clash and the
permanent-delete guard), against `LeaveTypeRequest::rulesFor()`.

- **Reads** (`setup.leave-types.view`): `find_leave_types`; `get_leave_type` — the
  settings and this year's use (approved requests and days taken, pending, and how many
  people have an entitlement of their own).
- **Writes** (`setup.leave-types.manage`):
  - `create_leave_type`: the code is upper-cased and unique, the flags default as on the
    screen, and the colour is the editor's first palette colour no live type wears;
  - `restore_leave_type`;
  - **`update_leave_type` and `archive_leave_type` always wait for Confirm.** The card
    says how many people's balance is the type's default this year and how many requests
    are pending; the reply lists each change ("Default: 15 days → 18 days").
- A name another type has in any case is refused. Permanent deletion stays on the screen.
- **Retrieval:** "what leave types do we offer?" carries the catalogue.

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
