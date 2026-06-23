# 0009 — Leave management: an approval workflow with derived balances

- **Status:** Accepted
- **Date:** 2026-06-11
- **Related:** [Leave module](../modules/leave.md),
  [leave tables](../database/leave-tables.md), [ERD](../database/erd.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0008 — Company Setup](./0008-company-setup-org-structure.md)

## Context

With the hire → onboard → employee chain in place, the next operational need is
**time off**: employees take leave, someone approves it, and the organisation tracks
how much each person has left. `work_schedules` already existed as a lookup but nothing
consumed it; there was no concept of leave at all.

Leave has three moving parts that pull in different directions:

1. **Kinds of leave** (Vacation, Sick, …) — slow-changing *configuration*.
2. **Requests** — the day-to-day workflow, with an approval lifecycle.
3. **Balances** — how much entitlement each employee has used and has left.

The risk with balances is **drift**: if "used days" is a stored counter, every approve /
reject / cancel / edit must adjust it in lock-step, and one missed path corrupts the
number permanently.

## Decision

Build **Leave Management** as an operational module (route prefix `/leave`, its own
`routes/leave.php`) to the established pattern, and put the **leave types** under
**Company Setup** (`/setup/leave-types`) since they are configuration — mirroring how
Departments sit in Company Setup.

- **Balances are derived, never stored as counters.** A `leave_balances` row holds only
  the **entitlement** (allocation) for an employee + type + year — or, when absent, the
  type's `default_days`. **Used** and **pending** days are always computed by summing
  approved / pending requests for that type and year ({@see LeaveBalanceService}). A
  balance therefore *cannot* drift: there is exactly one source of truth (the requests).
- **Approval lifecycle:** `pending → approved | rejected`, plus `cancelled`. A type may
  set `requires_approval = false`, in which case a filed request is **auto-approved**.
  The employee's linked user (if any) is notified of the outcome; reviewers
  (`hr-manager`) are notified of new pending requests.
- **Working days, server-computed.** The chargeable `days` are never trusted from the
  client — {@see LeaveCalculator} counts Mon–Fri in the range (a single day may be a
  half day = 0.5). Public holidays are **not** modelled yet (there is no holiday
  calendar); that is the one place to teach when Company Setup gains one.
- **The interface is an approvals inbox, not a form-heavy table.** The main page is a
  status-tabbed **queue** of request rows with inline approve / reject and a review
  drawer (which shows the balance impact); balances are a per-employee list with an
  adjust drawer. Tables are avoided where a queue / list reads better.
- **Types are archived, not destroyed.** `leave_requests.leave_type_id` is
  `restrictOnDelete`, so a type with history can't be hard-deleted; the UI archives
  (soft-deletes) instead, and only a type with **no** requests may be permanently
  deleted. `code` is **unique per tenant** via a partial index (the Departments pattern).
- **Actor / subject convention holds:** a request is *for an employee*; `filed_by` and
  `reviewed_by` reference the acting **users**.

## Alternatives considered

- **Stored used/pending counters on `leave_balances`.** Faster reads, but fragile — every
  lifecycle transition must mutate them correctly. Rejected: derivation is cheap at HR
  scale and is correct by construction.
- **Leave types inside the Leave module.** They are configuration that other surfaces
  read, so they belong in Company Setup — consistent with Departments, and matching the
  existing sidebar IA (`Company Setup → Leave Types`).
- **A calendar-first UI.** A month calendar is attractive but secondary to the core job
  (clearing the approval queue and checking balances); it can be layered on later over
  the same data.
- **Counting calendar days** instead of working days. Simpler, but overstates leave;
  excluding weekends matches how entitlements are actually consumed.

## Consequences

- **Positive:** balances are always correct; the approval queue is the natural UX;
  multi-tenant-safe type codes; the module reuses notifications, activity log, hashids
  and tenancy with no new infrastructure.
- **Negative / watch-outs:**
  - Balance figures are computed per view. The queries are grouped aggregates and the
    balances page is capped (200 employees); a very large org would want pagination or a
    materialised summary.
  - ~~**Holidays are not deducted** yet, so a range spanning a public holiday over-charges
    by those days until a holiday calendar exists.~~ **Resolved** — the
    [Work Schedule & Holidays](../modules/work-schedule-holidays.md) calendar lands; via
    `HolidayCalendar`, `LeaveCalculator` now skips non-working holidays.
  - A request is attributed to the **year of its start date**; leave that straddles a
    year boundary counts wholly against the start year (acceptable; revisit if needed).
