# Global search: ⌘K finds people, records, screens and help, and opens them

The search field in the top bar had never done anything. It now opens a **command
palette**, from the field, the icon on narrow screens, or **⌘K** / **Ctrl+K** on any
page. It finds employees, applicants, job postings, onboarding and offboarding cases,
leave requests, appraisals, training, events, departments, users, roles, screens and
help articles, all read for the person searching. A result opens the record itself,
including records that live in a drawer over their list. See
[ADR 0069](../decisions/0069-global-search-a-command-palette-read-for-the-searcher.md)
and the [module doc](../modules/global-search.md).

## Highlights

- **One place to look.** Fourteen kinds, grouped in a fixed order, five each, closest
  match first; the words that matched are picked out. With nothing typed it lists the
  screens you can open.
- **Every word counts.** "maria santos" finds Maria Santos, not every Maria; "maria q3"
  finds her Q3 appraisal.
- **Straight to the record.** Employees, leave requests, applications, departments,
  users and roles open with their drawer already showing.
- **Never more than your role.** A kind you cannot open is not searched; each kind
  checks exactly the permission its screen's route checks, and a test holds every link
  to it. Archived records, leave and exit reasons, and pay or ID fields are never
  searched or shown.
- **Keyboard first.** ↑ ↓ ↵ Esc; *Ask the assistant* with your words typed, when the
  assistant is offered.

## Backend

- `GET /search?q=` (`search`, `throttle:search` = 90/min/person) →
  `SearchController` → `GlobalSearch`, which asks an ordered list of `SearchSource`s.
  Record sources extend `RecordSource` and reuse each model's `scopeSearch()` once per
  word; ranking is a portable `case` expression (`lower()` and `like … escape '!'`).
- New `scopeSearch()` on `TrainingProgram`, `Event`, `OnboardingCase`,
  `OffboardingCase`, `LeaveRequest` and `PerformanceEvaluation`. The existing list
  queries do not call them, so no screen changes behaviour.
- No tenant bound → no results (the tenant scope is a no-op then). Users read through
  `inCurrentOrganization()`.
- `bootstrap/app.php`: `/search` renders errors as JSON (401/422/429), like `api/*`.

## Frontend

- `features/global-search/`: the palette (Radix dialog, ARIA combobox/listbox), the
  trigger, a debounced, aborting `useGlobalSearch`.
- `hooks/use-linked-record.ts`: list pages open the row named by `?open=`, then drop it
  from the address. Wired into Employees, Leave, the recruitment board, Departments,
  Users and Roles.
- The dead search input and icon in `app-sidebar-header.tsx` are replaced.

## Docs

- ADR 0069, `docs/modules/global-search.md`, and a Help Center article, *Searching
  SYNAPSE*; *Finding your way around* lists Search in the top bar.
- `docs/README.md` indexes the new module, ADR and entry, and the ADR and changelog
  entry of 2026-10-05-03, which were missing from it.

## Notes

- 35 new tests (`tests/Feature/Search/`); the suite is 1,596.
- The SQLite path could not be run: the migrations already fail on SQLite here. The
  driver-aware SQL was read by hand.
- The mobile app has no global search; attendance records are not searched.
