# 2026-06-15 — Recruitment board: view switch, posting details & a day fix

Three fixes to the Recruitment postings board (`/recruitment`): candidate cards
showed a fractional "days" number, a posting could only be opened into its pipeline
(never just *read*), and the board was a single fixed table. This adds a
table/card-grid switch and a read-only posting details drawer.

## Highlights

- **Whole-day ages.** Application cards showed an age like `23.319444456898d` —
  Carbon 3's `diffInDays()` returns a float. `JobApplicationResource.age_days` is
  now cast to a whole number, so cards read `23d`.
- **View a posting's details.** Selecting a posting (title, card, or the row menu's
  "View details") opens a read-only drawer: status, overview (department, position,
  type, closing date, posted by), a pipeline summary (openings filled / active /
  total), the public application link (copy + open), and the full description and
  requirements — with **Open pipeline** and **Edit** actions. Previously the only
  way into a posting was straight to the pipeline board.
- **Table / card views.** A new view switch in the toolbar toggles between the dense
  **table** and a **card grid**; the preference is remembered per browser
  (localStorage). Status/department filters stay as toolbar dropdowns. The
  pipeline-count chip and the row menu still jump to the board.

## Backend

- `JobApplicationResource`: `age_days` → `(int) $applied_at->diffInDays()` (null-safe).

## Frontend

- New `features/recruitment/`: `hooks/use-postings-view.ts` (persisted view choice),
  `components/postings-grid.tsx` (card grid), `components/posting-detail-sheet.tsx`
  (read-only drawer).
- `postings-toolbar` gained the **table/card view toggle** (and keeps the status and
  department filters). `pages/recruitment/index.tsx` wires the view switch, the
  table/grid swap, and the details drawer. `postings-table` / `postings-grid` /
  `posting-row-actions` gained an `onView` handler (title/card click and a "View
  details" menu item open the drawer). `types.ts` adds `PostingsView`.

## Docs

- [recruitment module](../modules/recruitment.md) — Surfaces and Frontend sections
  updated for the tabs, view switch, and details drawer.

## Notes

- Verified: `php -l` + Pint passed on the changed resource; a plain PHP/Carbon check
  confirmed `diffInDays()` returns `23.319…` and the `(int)` cast yields `23`; the
  frontend `tsc`, ESLint, Prettier, and `npm run build` are all green. The Pest suite
  can't run on this machine (no `pdo_sqlite`) — no recruitment tests were changed.
- No backend behaviour, routes, or schema changed beyond the single resource cast.
