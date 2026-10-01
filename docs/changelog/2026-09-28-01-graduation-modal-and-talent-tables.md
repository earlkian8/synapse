# Model graduation in a modal; Talent Acquisition and Offboarding in tables

Two consistency passes:

- **Model graduation** on Promotion Readiness, Performance Forecast and Attrition Risk is
  no longer a long panel that unfolds into the page. It is a one-line strip that opens a
  **centred modal** with one question per tab.
- **Recruitment, Onboarding and Offboarding** are now built from the same table kit as
  the Workforce modules. Every list is a table, including the onboarding and clearance
  checklists. Offboarding's forms are now centred modals, like Onboarding's. See
  [ADR 0048](../decisions/0048-talent-acquisition-and-offboarding-join-the-table-kit.md).

## Model graduation

- **The strip on the page** has one line:
  - whose model is scoring the page, as a pill;
  - one dash per requirement, with "3 of 5 requirements met";
  - the single thing furthest from ready ("Furthest to go: … — 86 more promotions").

  It is tinted, and its button turns primary, only when a decision is waiting (a model
  that passed its check, or the gate is open). The button opens the tab that decision
  needs, and the requirement count opens *Requirements*.
- **The modal** has a header with whose model is scoring, the requirement count and
  the examples on record. Its tabs:
  - **Overview**: the *next step* with a button to the tab that does it, *Where this
    page is* (the three stages), and *What is model graduation?*.
  - **Requirements**: one table, with a header row per group and a row per requirement
    (status, summary, progress meter, *Still needed*, *Furthest to go*). A row opens the
    requirement in place, with a way back: what to do, when it might be met, why the
    threshold is that number, and what is counted. This replaces the separate "Why this
    number?" dialog.
  - **Your model**: the model in use, one that passed, or the last check that failed.
    The check is now a small table (your model, the general model, knowing nothing).
  - **Data used**: the field coverage as one table grouped by whether a field reaches the
    score. It is no longer collapsed.
- **When only a derived requirement is left** (the prediction service being down), the
  strip and the overview name it as *Still needed*. Before, they said nothing, or "1
  requirements still to meet".
- **The switch confirmation no longer changes its wording as it closes.** Clearing the
  request is what closed it, so a "Switch back…?" dialog faded out reading "Switch to
  your own model?". `useGraduation` now keeps the last request and tracks `confirming`
  on its own.
- **Train on our records** is in the modal's pinned footer, so it is in view on every
  tab. It is locked until every requirement is met, and secondary while a passed model
  awaits the switch. Switching either way still asks first.
- **Files:**
  - New: `graduation-dialog`, `graduation-summary`, `requirements-table` and
    `requirement-detail`.
  - Rewritten: `graduation-panel` (now the strip), `field-coverage` (now a table) and
    `training-panel`.
  - Removed: `requirement-checklist` and `requirement-dialog`.
  - `MODEL_COPY` gains each surface's page name.

## Recruitment

- **Postings** use the kit:
  - six stat tiles; search, department and status filters with Reset; *Export* and *New
    posting*;
  - sortable headers and the shared pagination.
  - A row opens the posting's pipeline, and *View details* is in the row menu. The card
    grid and its view switch are gone.
- **The pipeline** uses the kit:
  - A header with the posting's facts and screening criteria, and *Add candidate*.
  - The decision support as stat tiles and a one-line recommendation.
  - A toolbar with candidate search, a stage filter with a count per stage, the sort,
    Reset, a **Table / Board** switch and *Export*.
  - **The table is now the default view.** It is paged 25 at a time, a row opens the
    candidate, and the menu moves, hires or rejects them. The Kanban board is still one
    click away and remembered per browser (the key moved to `…pipeline.view.v4`).
- Removed: `postings-grid`, `postings-pagination`, `postings-toolbar`,
  `pipeline-toolbar` and `use-postings-view`. The `PostingsView` type and the module's
  own `PER_PAGE_OPTIONS` are gone too.

## Offboarding

- **The list** uses the kit:
  - four stat tiles; search, exit type, department and status filters with Reset;
    *Export* and *Start offboarding*.
  - **One sortable, paged table** of exits: employee, department, exit type, status,
    clearance with flags, and last day (red once an exit in flight has passed it).
  - A row opens the case. **The row menu** opens the clearance, exports its sheet, and
    completes (confirmed, naming outstanding items), reopens, cancels (confirmed) or
    deletes (confirmed) the exit. The card grid and its view switch are gone.
- **The case page** uses the kit's header. **The clearance checklist is a table**: a
  header row per signing department with *Clear all (n)*, and a row per item with a
  tick-box, status, who signed it off and when, and remarks. For managers the row opens
  the item.
- **Forms are centred modals** (`Modal` + `FormField` / `FormSelect`): start
  offboarding, clearance item, exit details, and add from template.
- Removed: `offboarding-case-card`, `offboarding-toolbar`, `use-cases-view`,
  `clearance-checklist`, `clearance-item-row` and the three `*-sheet` forms.

## Onboarding

- **The case checklist is a table**, like clearance:
  - a header row per category with its done count;
  - a row per task with a tick-box, status badge, owner, due date (red when overdue)
    and who completed it;
  - the menu marks a task in progress, skips, edits or deletes it.
- New `task-table` and `TASK_STATUS_STYLES`. `task-checklist` and `task-row` are
  removed.

## Verification

- **Pest:** the full suite passes (1112 tests). No PHP changed.
- tsc, ESLint, Prettier and the build pass.
- **Real browser:** headless Chromium against a freshly seeded throwaway database, with a
  passed promotion model and a failed attrition check planted as database rows (training
  needs the inference service).
  - **Model graduation (all three pages):**
    - the strip, and every tab;
    - a requirement opening in place and going back, by mouse and by Enter;
    - arrow and End keys on the tabs, Close and Escape;
    - the requirement count opening *Requirements*, and the footer's locked train button;
    - switching to the passed model and back through both confirmations, and the failed
      check.
  - **Recruitment:**
    - search, filters, sort and Reset;
    - *View details* and *New posting*;
    - a row opening the pipeline.
  - **Pipeline:**
    - the table as the default, the stage filter with counts, sort, search and the
      empty state;
    - a row opening the candidate;
    - *Hire* asking first, and *Move to…*;
    - *Board* remembered across a reload, and the back arrow.
  - **Offboarding:**
    - filters, Reset and sort;
    - *Complete exit* asking first, and cancel then reopen;
    - starting an exit.
  - **Clearance:**
    - tick, edit, flag, *Clear all* and *Add item*;
    - *Edit details* and *Add from template*;
    - delete (confirmed).
  - **Onboarding checklist:** tick, edit, skip and un-skip.
  - **Dark mode** was checked. At phone width no page scrolls sideways and the modal fits.
    There were no console errors besides the known seeded-avatar CSP blocks.

## Notes

- **The server is unchanged:** no controller, route, resource or prop changed.
- Company Setup's program and template screens still use their own pieces
  (ADR 0048, *Consequences*).
