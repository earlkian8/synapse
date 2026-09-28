# Assistant: Locations, Leave Types, Award Types, Performance Framework

The assistant gains retrieval (RAG) and function calling for four more Company Setup
screens:
- the **work locations**;
- the **leave types**;
- the **award types**;
- the **performance framework**: frameworks, criteria, rating scales and review cycles.

Every change goes through the screen's own path and rules. Changes that reach other
people wait for a Confirm that says whom. See
[ADR 0055](../decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md).

## Highlights

- **Answered from the records, before the model is called:** "what sites do we have?",
  "what leave types do we offer?" and "what does our appraisal framework measure?".
  "Where is Maria based?" needs the directory permission too.
- **What works on a plain instruction:**
  - "Base Maria and Ben at the Cebu plant as their primary site";
  - "create a Birthday Leave, 1 day a year";
  - "retire Perfect Attendance";
  - "copy the Staff framework as Sales".
- **What waits for a Confirm that says whom it reaches:**
  - "make the Makati fence 300 m";
  - "give everyone 18 days of vacation leave";
  - "add Customer focus to the Staff framework at 15%";
  - "open the H2 cycle".
- **Never placed by the model:** a site's pin. Creating a site and moving its fence stay
  on the map.
- **Two defects fixed:**
  - Which framework a person is appraised against: the resolver's "most specific,
    then default, then oldest" was not actually applied.
  - A full name with a suffix ("Juan Cruz Jr.") found nobody, in the directory search
    and in every assistant lookup.

## Backend

- **`LocationsModule`:**
  - reads: `find_locations`, `get_location`;
  - writes: `base_at_location`, `unbase_from_location`, `restore_location`;
  - confirmed: `update_location`, `archive_location`;
  - retrieval: the sites, and the policies that check fences.
- **`LeaveTypesModule`:**
  - reads: `find_leave_types`, `get_leave_type`;
  - writes: `create_leave_type`, `restore_leave_type`;
  - confirmed: `update_leave_type`, `archive_leave_type`;
  - retrieval: the catalogue.
- **`AwardTypesModule`:**
  - reads: `find_award_types`, `get_award_type` (counts only, never recipients);
  - writes: `create_award_type`, `update_award_type`, `restore_award_type`;
  - confirmed: `archive_award_type`.
- **`PerformanceFrameworkModule`:**
  - reads: `find_frameworks`, `get_framework` (with who it covers today),
    `find_kpi_criteria`, `find_rating_scales`, and `find_review_cycles` (only without
    `performance.view`);
  - writes: `create_framework` (a copy, or from criteria; applies to everyone, not the
    default), `add_kpi_criterion`, `create_rating_scale`, `set_default_rating_scale`,
    `create_review_cycle`;
  - confirmed: `update_framework`, `set_framework_item`, `remove_framework_item`,
    `set_framework_section`, `set_default_framework`, `archive_framework`,
    `update_kpi_criterion`, `archive_kpi_criterion`, `update_review_cycle`;
  - retrieval: the frameworks and whom each applies to.
- **Shared workflows:**
  - `WorkLocationWorkflow`, `LeaveTypeWorkflow`, `AwardTypeWorkflow` and
    `PerformanceFrameworkWorkflow`, each with its exception;
  - the location, leave-type, award-type, framework, scale, criterion and cycle
    controllers are thin callers, with the same toasts and audit lines;
  - the location screen's "people" and the assistant's base / unbase share the workflow.
- **Requests usable without a route:**
  - `WorkLocationRequest::rulesFor()`;
  - `LeaveTypeRequest::rulesFor()` / `normaliseCode()`;
  - `ReviewTemplateRequest::documentRules()` / `normalise()` / `validateDocument()`;
  - `RatingScaleRequest::normalise()`.
- **`TemplateResolver`** ranks with one explicit comparator.
- **`Employee::scopeSearch()`** searches `suffix` too. A name is searched word by word,
  and "Jr." matched no column. This also made a cross-tenant assistant test fail about
  one run in ten.
- **Imperatives** gain base, retire, reactivate and copy.

## Frontend

- **The chat button** also appears for `setup.locations.view`, `setup.leave-types.view`,
  `setup.award-types.view` and `setup.kpi.view`.
- **New suggestions:** "What sites do we have?", "What leave types do we offer?" and "What
  does our appraisal framework measure?".

## Verification

- **Pest:** the full suite passes (1315 tests).
  - That is the previous 1307 plus 37 new, less the 29 in the removed
    `AttendanceDevicesTest` and the dropped Devices wizard case.
  - The resolver's new test fails on the old code.
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database, light and dark:
  - `/kiosk` and `/setup/devices` answer 404, and nothing links to them;
  - an `update_leave_type` was held through the real assistant. Its card read "…this
    year's balance for 114 people without one of their own…" and waited for Confirm;
  - confirming set Vacation Leave to 18 days a year, toasted, refreshed the page, and
    was audited via assistant;
  - no console errors.

## Tests

- `Setup/LocationsAssistantTest` (8)
- `Setup/LeaveTypesAssistantTest` (6)
- `Setup/AwardTypesAssistantTest` (4)
- `Setup/PerformanceFrameworkAssistantTest` (9)
- `Setup/WorkLocationTest` (2) and `Setup/AwardTypeTest` (2): the screens through their
  workflows. These had no write tests before.
- `Setup/PerformanceFrameworkTest` (+1): the resolver's order.
- `Employee/EmployeeTest` (+1): a full name with a suffix is found.

## Notes

- **Assistant coverage:**
  - Employees, Leave, Attendance, Recruitment, Onboarding, Offboarding;
  - Performance, Training, Awards, Events, Departments;
  - Company Profile, Work Schedule & Holidays, Attendance Policies, Locations, Leave
    Types, Award Types, the Performance Framework;
  - the Dashboard and Reports.

  Still screen-only (onboarding programs already have tools of their own):
  - recruitment pipelines, and editing the offboarding clearance templates;
  - user and role management;
  - the analytics pages.
- **Tool budget:** a super admin is offered about 23,000 tokens of tool declarations and
  6,600 of guidance on every turn. ADR 0055 names the next lever.
