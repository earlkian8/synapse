# Onboarding reads programs → people → checklist

Onboarding opened on a wall of person cards, whatever program each person was on.
It now reads top-down in three levels, each laid out like the Employees module
(header, stat tiles, toolbar, table, pagination) and kept compact:

1. **Programs:** every onboarding program in a table, with how its onboarding is going.
2. **People:** clicking a program opens the employees it is onboarding, in a table.
3. **Checklist:** clicking an employee opens their current onboarding.

See [the module doc](../modules/onboarding.md#surfaces).

## Highlights

- **Programs table** (`/onboarding`). Each program shows:
  - who it applies to and its task count;
  - how many people it is onboarding and how many it has completed;
  - the progress of its in-flight checklists, and their overdue tasks.

  Cases on no program get an **Unassigned** row, which only appears when there are
  some. You can search programs by name, and a row's menu can **Start onboarding
  here** with that program pre-selected.
- **A program's people** (`/onboarding/programs/{program}`, and `…/unassigned`). Each
  row shows:
  - the employee, department and position;
  - status and checklist progress;
  - overdue tasks;
  - start and target dates (a missed target shows in red).

  The page has search, department and status filters (every status by default), sort
  by employee, start or target date, and paging. The stats cover that program only.
  Each row's menu opens the checklist, marks it complete, reopens it, cancels it or
  deletes it. Deleting keeps you on the program.
- **The checklist** now has breadcrumbs *Onboarding › program › person*. The back arrow
  (and the program name in the summary) return to the program. The category groups sit
  two abreast on wide screens.
- **Compact.** The stat tiles hold icon, label and value on one line, table rows are
  tighter, and the gaps are smaller throughout.

## Server

- **`OnboardingProgramsOverviewQuery`** (new) builds every program's figures from two
  grouped queries (cases per program; tasks of active cases per program) rather than
  one query per row, and adds the Unassigned row.
- **`OnboardingProgramCasesController`** (new): `show` and `unassigned`, behind
  `onboarding.view`. The routes `onboarding.programs.show` and
  `onboarding.programs.unassigned` sit ahead of the `{case}` wildcard.
- **`OnboardingCasesIndexQuery::paginate()`** is scoped by a closure, sortable, and
  paged at 10/15/25/50/100.
- **`OnboardingStatistics::toArray($scope)`** gives a program's own figures.
- **`OnboardingCaseController`**:
  - `index` renders the programs.
  - `destroy` returns to the case's program, or to Unassigned.
  - The lifecycle toast reads "Onboarding completed / cancelled / reopened." It used
    to print the raw status slug, e.g. "Onboarding in_progress."
  - *Start onboarding* only offers people on the roster. With the workforce history,
    it had started listing people who had left.
- **`OnboardingCaseResource`**: the case's program carries its `hashid`, for the way back.

## Frontend

- **Pages:** `onboarding/index.tsx` (programs, rewritten), `onboarding/program.tsx` (new),
  `onboarding/case.tsx` (breadcrumbs, back link, tighter spacing).
- **New components:** `programs-overview-table`, `cases-table`, `case-row-actions`,
  `onboarding-pagination`, `search-input`.
- **New hooks:** `use-case-filters`, `use-program-search`.
- `start-onboarding-dialog` takes a `programId`, and the stat tiles are compact.
- Removed: the card board (`onboarding-case-card`, `onboarding-toolbar`,
  `use-onboarding-filters`).
- In the new tables, whole rows are clickable, and the name is also a real link.
  Sortable headers keep the uppercase style of the others, and the current page stays
  visible in dark mode.

## Verification

- **Pest:** `OnboardingTest` covers:
  - the overview's figures, Unassigned row and search;
  - who can be started;
  - a program's people, with filters, sort, paging and scoped stats;
  - the unassigned page;
  - tenant isolation;
  - delete returning to the program;
  - the lifecycle toasts.

  64 onboarding tests pass, and the full suite is green.
- **Real browser** (headless Chromium, against a freshly seeded throwaway database):
  29 checks pass, covering every filter, sort, search and row action, starting, ticking
  and adding tasks, back navigation, deleting, both row clicks and the unassigned page,
  in light, dark and phone widths. The only console errors are the known seeded-avatar
  CSP blocks.
- tsc, ESLint, Prettier, Pint and the build pass.

## Notes

- The mixed-case sortable headers and the dark-mode pagination fixed here are shared
  code in other modules too:
  - sort headers: Employees, Users, Roles, Activity Logs, Recruitment, Attendance;
  - pagination: Employees, Users, Roles, Activity Logs, Recruitment, Reports.

  They were left as they are.
