# 2026-06-11 — Leave Management module

Time off, end to end: file requests, approve / reject them, and track balances. See
[ADR 0009](../decisions/0009-leave-management.md) and the
[module doc](../modules/leave.md).

## Summary

- **Approvals inbox** (`/leave`) — a status-tabbed queue of requests with inline
  approve / reject, a review drawer showing the **balance impact**, and filters
  (search, type, department).
- **Balances** (`/leave/balances`) — per-employee entitlement / used / remaining per
  type for a year, with an adjust drawer. **Used and pending are derived from requests,
  never stored**, so they can't drift.
- **Leave types** (`/setup/leave-types`, Company Setup) — a card grid with colour,
  default entitlement and policy flags (paid, half-day, requires-approval, active);
  archive / restore / permanent-delete.
- Working-day day counts (weekends excluded; half-day aware); approval lifecycle with
  optional **auto-approval**; notifications to reviewers and the employee.

## Backend

- Migration `…_create_leave_tables`: `leave_types` (per-tenant unique `code` partial
  index, soft-deletes), `leave_balances` (unique per employee/type/year, entitlement
  only), `leave_requests` (lifecycle; `leave_type_id` `restrictOnDelete`).
- Models `LeaveType`, `LeaveRequest`, `LeaveBalance` (+ `Employee` leave relations);
  `LeaveCalculator` (working days / half day, `CarbonInterface`-safe);
  `LeaveBalanceService` (derives balances via grouped aggregates).
- Controllers `Leave\LeaveRequestController`, `Leave\LeaveBalanceController`,
  `Setup\LeaveTypeController`; requests for filing, balances and types; resources;
  `LeaveRequestsIndexQuery` + `LeaveStatistics`.
- `routes/leave.php` (`leave.*`) wired into web.php; `leave-types.*` added to
  `routes/setup.php`. Mutations activity-logged.
- Permissions: a **Leave Management** group (`leave.view`, `leave.request`,
  `leave.manage`) and two **Company Setup** entries (`setup.leave-types.view/manage`),
  granted to Super Admin / Administrator / HR Manager.
- `LeaveSeeder` seeds seven PH-style leave types plus demo balances and requests across
  statuses (wired into `DatabaseSeeder`).

## Frontend

- `features/leave/` — inbox + balances (stats, status tabs, request rows, file-leave
  sheet, review drawer with a balance meter, balances toolbar/cards/adjust sheet, a
  Requests/Balances nav). Pages `pages/leave/{index,balances}`.
- `features/leave-types/` — Company Setup card grid + form sheet (colour picker, policy
  switches). Page `pages/setup/leave-types`.
- Sidebar: **Leave Management** gated on `leave.view`; **Leave Types** gated on
  `setup.leave-types.view`.

## Tests

- `tests/Unit/LeaveCalculatorTest.php` — 7 DB-free day-math tests.
- `tests/Feature/Leave/LeaveTest.php` — inbox, filing + day computation, auto-approval,
  half-day guard, approve/reject (+ already-reviewed guard), cancel/delete, balances
  render, setting entitlements, **derived** used/pending, authorization, isolation.
- `tests/Feature/Setup/LeaveTypeTest.php` — render, create + validation, per-tenant
  duplicate + cross-org reuse, update, archive/restore/force-delete (+ force blocked
  when requests exist), authorization, isolation.

## Verification

`tsc`, ESLint, Pint clean; `npm run build` succeeds (`leave` ~38 kB, `leave-types`
~20 kB). The unit suite passes locally (7/7). Against live Postgres: leave-types,
inbox and balances render; filing computes working days (5-day VL = 5.0); a half-day
across two days is rejected and a single half-day charges 0.5; approve sets the
reviewer; derived balances reflect approved/pending sums. (The Feature suite needs
`pdo_sqlite` / CI.)

## ⚠️ Migration note

Run `php artisan migrate` and re-seed roles
(`php artisan db:seed --class=RolePermissionSeeder`) so existing roles pick up the
**Leave Management** and **Leave Types** permissions. `php artisan db:seed` is idempotent
and adds the default leave types + demo activity.
