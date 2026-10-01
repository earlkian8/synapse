# 0052 — The assistant shapes the org structure, but not pay, attendance rules or permanent deletes

- **Status:** Accepted
- **Date:** 2026-09-28
- **Extends:**
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md);
  - [0027 — Assistant employee retrieval & disclosure policy](./0027-assistant-employee-retrieval-and-disclosure-policy.md)
    (its pay rule, applied to positions).
- **Related:** [Departments](../modules/departments.md#the-assistant).

## Context

Departments were the last module without an assistant capability. The Departments
screen manages four things beyond the tree itself:

- **a position's salary band** (`salary_grade_min` / `salary_grade_max`);
- **a department's default schedule and attendance policy**, which decide how each
  member's day is judged (ADRs 0037, 0038);
- **permanent deletion** of an archived department, which nulls out every employee's,
  position's and sub-department's link to it;
- the ordinary structure: names, codes, parents, heads and positions.

ADR 0027 says the assistant does not answer questions about pay. A position's band is
not a person's salary, but a band read beside "Maria is a Senior Analyst" is an
estimate of hers. The attendance settings and permanent deletion are not about
disclosure. They are about reach: one sentence would re-judge a department's
attendance, or irreversibly detach everyone in it.

## Decision

1. **The assistant reads and changes the structure:** departments (name, code, parent,
   head, description, archive, restore) and positions (title, description).
   `DepartmentWorkflow` is the one path, and `DepartmentRequest::rulesFor()` supplies
   the screen's own validation to a caller with no route. So the per-tenant unique
   code and the cycle guard hold, in the screen's words.
2. **Salary bands are never read or set in chat.** No card, function response or
   brief carries one, and no tool declares a salary parameter. A test pins it.
3. **Default schedule, attendance policy and permanent deletion stay on the screen.**
   The assistant says so and points to `/setup/departments`.
4. **Archiving a department and deleting a position wait for Confirm** (ADR 0049).
   Their replies say how many people stay assigned or lose their position.
5. **Catalogs are not repeated.** Someone with `employees.view` already gets the
   department list from the Employees capability, so this module lists departments
   only for those who do not.

## Consequences

- **"Who heads Finance?", "move Logistics under Operations", "add a Dispatcher
  position" work in chat.** "What does a Senior Analyst earn?" gets a plain "not
  available here".
- **A position's title stays unique within its department when added from chat.** The
  screen does not enforce this; in chat it stops a repeated request from creating a
  duplicate.
- **This completes the planned set.** The assistant covers:
  - Employees, Leave, Attendance, Recruitment, Onboarding, Offboarding;
  - Performance, Training, Awards, Events;
  - Departments, the Dashboard and Reports.

  Each follows ADR 0049's defences and its screen's own rules through a shared
  workflow. Still screen-only:
  - the other Company Setup screens (schedules, holidays, locations, policies, leave
    and award types, templates);
  - user and role management;
  - the analytics pages (attrition, promotion, forecasts, model graduation).

## Alternatives considered

- **Showing bands to `setup.departments.view` holders, as the screen does.** The
  screen is a configuration surface that someone opens on purpose. A chat answer can
  be pulled into any conversation, including one steered by injected text. ADR 0027
  already drew this line for pay.
- **Allowing schedule and policy changes behind a Confirm.** A confirmation card shows
  *what* changes, not what it does to hundreds of attendance days. That judgement
  belongs on the attendance setup screens, which show it.
