# 2026-06-15 — Recruitment pipeline: table + card grid with stage tabs

The postings board got a table/card-grid switch earlier today; the **pipeline**
(`/recruitment/{posting}`, reached via a posting's "Open pipeline") only had a kanban
board. This brings it in line with the postings board: a flat **table (the default)**
and a **card grid**, with **stage tabs** filtering both. The old kanban board/column
layout is removed.

## Highlights

- **Table by default, plus a card grid.** The pipeline opens as a flat table
  (candidate, stage, rating, interviews, applied age). A header toggle switches to a
  responsive **card grid** of candidate cards — the same grid treatment as the postings
  board. The choice is remembered per browser (localStorage), independently of the
  postings-board choice.
- **Stage tabs over both views.** **Stage tabs** — All · Applied · Screening ·
  Interview · Offer · Hired · Rejected, each with a live count — sit above both layouts
  and filter the candidates shown, and stay put when switching views.
- **Same actions either way.** Both the table and the grid cards expose the same
  per-candidate **Move / Hire / Reject** menu and open the candidate detail drawer on
  click — the menu is a single shared component, so the two can't drift.

## Frontend

- New `features/recruitment/`: `hooks/use-stored-view.ts` (generic localStorage-backed
  layout preference), `hooks/use-pipeline-view.ts` (table/grid, **defaulting to
  table**), `components/pipeline-table.tsx`, `components/pipeline-grid.tsx`, and
  `components/pipeline-stage-tabs.tsx` (stage filter with counts). `use-postings-view`
  was refactored onto the shared `use-stored-view` (no behaviour change).
- The Move / Hire / Reject menu was extracted from `application-card.tsx` into
  `components/application-actions-menu.tsx` and reused by both the card and the table.
- The kanban `pipeline-board.tsx` and `pipeline-column.tsx` were removed (the grid
  replaces them; `application-card.tsx` is reused as the grid cell).
- `pages/recruitment/pipeline.tsx` renders the always-visible stage tabs above the
  table/grid toggle, both fed by the same client-side stage filter (derived from the
  applications the page already loads). `types.ts` adds `PipelineView` and `StageFilter`
  (kept in `types.ts`, not the component file, so each component module stays
  component-only — a clean React Fast Refresh boundary).

## Docs

- [recruitment module](../modules/recruitment.md) — Surfaces and Frontend sections
  updated for the pipeline's table/grid switch and stage tabs.

## Notes

- No backend, routes, or schema changes — this is a frontend-only layout change over
  the same pipeline data the page already loads.
- Verified: `tsc`, ESLint, Prettier, and `npm run build` are all green. The Pest suite
  can't run on this machine (no `pdo_sqlite`); no backend code changed.
