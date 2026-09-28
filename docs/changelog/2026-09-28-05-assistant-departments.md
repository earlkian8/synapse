# Assistant: Departments

The assistant gains retrieval (RAG) and function calling for **Departments**, the org
structure: the department tree, its heads and the positions under each. It changes
the structure by the Departments screen's own rules. It deliberately leaves three
things to the screen: salary bands (they approximate pay), a department's attendance
settings, and permanent deletion. See
[ADR 0052](../decisions/0052-assistant-departments-without-pay.md).

## Highlights

- **"How is our org structure set up?", "who heads Finance?", "what positions does IT
  have?"** are answered from the tree. The structure question is answered before the
  model is called.
- **"Move Logistics under Operations", "make Rosa the head of Logistics", "add a
  Dispatcher position"** run on a plain instruction, with the screen's unique-code
  and no-cycles rules and its own messages.
- **Archiving a department and deleting a position wait for a Confirm**, and say how
  many people are affected.
- **Nothing about pay.** No read, reply or tool carries a salary band.

## Backend

- **`DepartmentsModule`:**
  - reads: `find_departments`, `get_department`, `list_positions`,
    `org_structure_summary`;
  - writes: `create_department`, `update_department`, `restore_department`,
    `add_position`, `update_position`;
  - confirmed: `archive_department`, `delete_position`;
  - retrieval: a structure topic (the tree, heads, departments with no head or nobody
    in them).
- **`Support\Setup\DepartmentWorkflow`** (with `DepartmentException`) holds every
  department and position write. `DepartmentController` and `PositionController` are
  thin callers of it, with the same toasts and audit lines as before.
- **`DepartmentRequest::rulesFor(?Department)`** and `normaliseCode()` give the
  screen's validation to a caller with no route. Before, the cycle guard read the
  department from the route, so no other caller could have used it.
- **`Module::invalid()`** takes a request's custom messages, so the cycle refusal
  reads exactly as on the screen.
- **The department catalog is not repeated** in the guidance for users who already
  get it from the Employees capability.
- **Imperatives** gain rename, restore and nest.

## Frontend

- **The chat button** also appears for `setup.departments.view`. Its comment, which
  had a duplicated doc block, now lists every module.
- **New suggestion:** "How is our org structure set up?" (for `setup.departments.view`).

## Verification

- **Pest:** the full suite passes (1268 tests: the previous 1256 plus 12 new). The
  existing `DepartmentTest` (19) passes unchanged on the refactored controllers.
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database: a held
  `archive_department` planted through the real assistant waited for Confirm in light
  and dark. Confirming archived the department ("Archived department "IT Support" via
  assistant") and toasted. No console errors.

## Tests

`Setup/DepartmentsAssistantTest` (12 tests):

- permissions, run-time refusal, and no permanent delete on offer;
- confirmations;
- no salary band in any read or tool declaration;
- the department read-out (head, tree path, sub-departments, positions) and the
  structure brief;
- upper-cased, unique codes;
- the cycle guard in the screen's words, and making a department top-level;
- heads from this workspace only, and removing one;
- archive with the affected count, and the restore code clash;
- positions added once, renamed, and deleted with the holder count;
- tenant isolation.

## Notes

- **Correction to the previous entry.** It said "the assistant now covers every
  module". With Departments, it covers:
  - Employees, Leave, Attendance, Recruitment, Onboarding, Offboarding;
  - Performance, Training, Awards, Events;
  - Departments, the Dashboard and Reports.

  Still screen-only:
  - the other Company Setup screens;
  - user and role management;
  - the analytics pages (attrition, promotion, forecasts, model graduation).
