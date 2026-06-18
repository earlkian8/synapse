# 0013 — Training & Development: in-module programs with a derived lifecycle

- **Status:** Accepted
- **Date:** 2026-06-18
- **Related:** [Training module](../modules/training.md),
  [training tables](../database/training-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Benefits ([ADR 0011](./0011-benefits-administration.md)) — the catalogue + enrollments pattern,
  Performance ([ADR 0012](./0012-performance-management.md)) — for the derive-don't-store norm

## Context

ERD §8 sketches `training_programs` (a program with a provider, date window and seat
`capacity`) and `training_enrollments` (employee ↔ program with a status
`enrolled | completed | dropped`, a `score` and a `completed_at`). The sidebar already
carried an ungated **Training & Development** (`/training`) placeholder, separate from
**Performance Management** (built in ADR 0012).

Unlike Benefits — where the plan **catalogue** is Company-Setup config and enrollment
is operational — a training program *is* an operational, scheduled thing (a cohort on
specific dates). There is no §2 config table for training, and creating a program is
the everyday act of running the module.

## Decision

Build Training & Development as a **single self-contained operational module** at
`/training`, reusing the Benefits "catalogue + enrollments" shape but with the
programs **created in-module** (no Company-Setup surface):

- **`training_programs`** — created, edited, archived (soft delete), restored and
  permanently deleted from the module itself. Addressed by hashid.
- **`training_enrollments`** — one row per employee per program; enroll, update
  (status / score / remarks) or remove. Enrolling is blocked when the program is full
  or the employee is already enrolled.

### A program's status is derived, not stored

Rather than add a status column the ERD doesn't have, a program's lifecycle
(`upcoming → ongoing → completed`) is **derived from today against its date window** —
the same "store the source, derive the rollup" rule Leave balances (ADR 0009),
Benefits cost rollups (ADR 0011) and the Performance overall score (ADR 0012) follow.
It can never drift from the dates.

### Justified refinements over the raw ERD (backward-compatible)

- **`end_date` and `capacity` are nullable** — an open-ended / self-paced course (no
  fixed dates) and an uncapped program are both allowed; an uncapped program is never
  "full".
- **`completed_at` is server-managed from the status** — stamped when an enrollment is
  marked completed, cleared otherwise — never trusted from the client (mirrors how
  Performance derives its score server-side).
- **`remarks` added to the enrollment** — a short completion / feedback note, paralleling
  the Benefits enrollment `notes`.

## Consequences

- The module is the management surface: the overview groups programs by derived status
  and also exposes the **archived** programs (restore / permanent delete), since there
  is no separate Setup page. A program with enrollments cannot be permanently deleted
  (archive instead) — the same guard Benefits plans use.
- A dedicated `training.*` permission set (`training.view` / `training.manage`) gates the
  module; the built-in **HR Manager** role gets both.
- The Employee detail drawer gains a read-only **Training** tab (alongside Benefits and
  Performance).
- **Out of scope (this cut):** training calendars / sessions, attendance per session,
  certificates & expiry, budgets / cost tracking, training-needs analysis from
  performance gaps, self-enrollment, and an assistant capability (matching the Payroll /
  Benefits / Performance precedent).
