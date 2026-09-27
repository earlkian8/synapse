# 0048 — Talent acquisition and offboarding join the table kit

- **Status:** Accepted
- **Date:** 2026-09-28
- **Extends:** [ADR 0047](./0047-workforce-list-pages-share-one-table-kit.md) (the kit,
  and "every list is a table")
- **Related:** [Recruitment](../modules/recruitment.md#surfaces),
  [Onboarding](../modules/onboarding.md#surfaces),
  [Offboarding](../modules/offboarding.md#surfaces)

## Context

ADR 0047 put every Workforce page on one kit, and left Recruitment for later. Talent
acquisition (Recruitment and Onboarding) and Offboarding were still out of step with it:

- **Recruitment** had its own toolbar, sort headers and pagination. Postings could be
  switched to a card grid, and the pipeline opened as a Kanban board by default.
- **Offboarding** had its own toolbar and six-line stat cards, and cases could be
  switched to a card grid. Its three forms slid in as side sheets, although Onboarding
  (its mirror image) had moved to centred modals.
- **The two checklists** (Onboarding's tasks and Offboarding's clearance items) were
  grouped cards of rows. Onboarding's list pages were tables already.

## Decision

**Recruitment, Onboarding and Offboarding are built from the same kit, and every list on
them is a table.**

1. **Lists are tables, with no card-grid alternative.** Postings, offboarding cases and
   pipeline candidates use `TableCard` / `DataTable`, `SortableHead`, `EmptyTableRow`,
   `rowOpens` and `RowMenuTrigger`, under `PageHeader`, `StatTiles` and `ListToolbar`.
   A row opens the next level down: a posting opens its pipeline, a candidate their
   application, and an exit its clearance.
2. **Checklists are tables grouped by header rows.** A category (onboarding) or a
   signing department (offboarding) is a full-width header row with its count and, for
   clearance, *Clear all (n)*. Items are rows with a tick-box, a status badge, the owner
   or signer, and a menu. This is the same shape as the appraisal scorecard.
3. **The pipeline table is the default, and the board stays.** The Kanban board is the
   one view with no table equivalent: every stage at once, and cards to advance. So it
   stays as a *Table / Board* switch in the toolbar, remembered per browser. The
   preference key moved to `recruitment.pipeline.view.v4`, so everyone starts on the
   table.
4. **Offboarding's forms are centred modals** on `components/modal.tsx` with
   `FormField` / `FormSelect`, the same as Onboarding.
5. **The server is unchanged.** Offboarding cases and pipeline candidates were sent
   whole and are paged in the browser. Postings stay paged on the server.

## Consequences

- The talent-acquisition and exit pages now look and behave like the Workforce ones.
  Removed:
  - Recruitment: `postings-grid`, `postings-pagination`, `postings-toolbar`,
    `pipeline-toolbar` and `use-postings-view`.
  - Offboarding: `offboarding-case-card`, `offboarding-toolbar`, `use-cases-view` and
    three sheets.
  - The checklists: `task-checklist`, `task-row`, `clearance-checklist` and
    `clearance-item-row`.
- A list row's menu can now move an exit through its lifecycle, as Onboarding's can.
  Completing an exit from the table asks first and names any outstanding items.
- Anyone who kept the board as their pipeline view lands on the table once, and can
  switch back with one click.
- Company Setup's program and template screens, Users, Roles, Activity Logs and Reports
  still use their own pieces. They can move to the kit when they are next redesigned.
