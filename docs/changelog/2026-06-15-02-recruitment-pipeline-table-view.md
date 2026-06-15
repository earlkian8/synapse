# 2026-06-15 — Recruitment pipeline: board ⇄ table view switch

The postings board got a table/card-grid switch earlier today; the **pipeline**
(`/recruitment/{posting}`, reached via a posting's "Open pipeline") only had the
kanban board. This adds the same kind of view switch there, so a recruiter can read
a posting's candidates as a flat table instead of columns.

## Highlights

- **Board or table on the pipeline.** A toggle in the pipeline header switches between
  the existing **kanban board** and a new **table** (candidate, stage, rating,
  interviews, applied age). The choice is remembered per browser (localStorage),
  independently of the postings-board choice.
- **Same actions either way.** Both layouts expose the same per-candidate
  **Move / Hire / Reject** menu and open the candidate detail drawer on click — the
  menu is now a single shared component, so the board card and the table can't drift.

## Frontend

- New `features/recruitment/`: `hooks/use-stored-view.ts` (generic localStorage-backed
  layout preference), `hooks/use-pipeline-view.ts` (board/table), and
  `components/pipeline-table.tsx`. `use-postings-view` was refactored onto the shared
  `use-stored-view` (no behaviour change).
- The Move / Hire / Reject menu was extracted from `application-card.tsx` into
  `components/application-actions-menu.tsx` and reused by both the card and the new
  table.
- `pages/recruitment/pipeline.tsx` renders the view toggle and swaps board/table.
  `types.ts` adds `PipelineView`.

## Docs

- [recruitment module](../modules/recruitment.md) — Surfaces and Frontend sections
  updated for the pipeline view switch and the shared actions menu.

## Notes

- No backend, routes, or schema changes — this is a frontend-only layout option over
  the same pipeline data the page already loads.
- Verified: `tsc`, ESLint, Prettier, and `npm run build` are all green. The Pest suite
  can't run on this machine (no `pdo_sqlite`); no backend code changed.
