# 0014 — Awards & Recognition: a recognition feed over a typed catalogue

- **Status:** Accepted
- **Date:** 2026-06-18
- **Related:** [Awards module](../modules/awards.md),
  [awards tables](../database/awards-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Benefits ([ADR 0011](./0011-benefits-administration.md)) — the config + operational split

## Context

ERD §9 sketches `employee_awards` (an employee received an award of a given type, on
a date, for a reason, granted by a user) and §2's `award_types` config (name +
description). The sidebar already carried ungated placeholders for **Awards &
Recognition** (`/awards`) and **Award Types** (`/setup/award-types`).

Unlike Benefits/Performance, an award is a **lightweight, flat record** — there is no
parent/child roster to navigate, no lifecycle to advance. The fitting surface is a
**recognition feed**, not a per-type detail page.

## Decision

Build Awards & Recognition as a **config layer + a flat operational feed**, reusing
the established split:

- **`award_types`** — a Company-Setup catalogue (`/setup/award-types`): name,
  description, an accent **colour** and an active flag; archivable (soft delete), like
  `benefit_plans` / `kpi_criteria`.
- **`employee_awards`** — the recognitions, surfaced at `/awards` as a **chronological
  feed** with KPIs (total, this month, people recognised, active types), a give-award
  dialog, and inline edit / remove. Addressed by numeric id (like enrollments) — no
  per-award page is needed.

### Justified refinements over the raw ERD (backward-compatible)

- **`award_types` gains `color` + `is_active`** — every other config table carries
  `is_active` (so retired types drop out of the give-award picker), and a per-type
  accent colour makes the recognition feed scannable (parallels `leave_types.color`).
- **`employee_awards.awardType()` is loaded `withTrashed()`** — so a past award still
  shows what it was for after its type is archived, without snapshotting a label onto
  every row (a lighter alternative to the payslip/performance label-snapshot, viable
  here because force-deleting a used type is blocked).

## Consequences

- The granting user is recorded as `awarded_by` (an actor FK to `users`, per the
  ERD convention); the feed shows "by {name}".
- A type that has been given out **cannot be permanently deleted** (archive instead) —
  the same guard Benefits plans / KPI criteria use; archiving keeps historical awards
  intact and resolvable.
- A dedicated `awards.*` / `setup.award-types.*` permission set gates the module and its
  configuration; the built-in **HR Manager** role gets both.
- The Employee detail drawer gains a read-only **Awards** tab (alongside Benefits,
  Performance and Training).
- **Out of scope (this cut):** nominations / approval workflows, points or reward
  redemption, peer-to-peer kudos, public recognition walls / feeds for non-HR users,
  and an assistant capability (matching the precedent of shipping operational modules
  without an AI module first).
