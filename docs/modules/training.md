# Training & Development

Run the organisation's **training programs** and track **who is enrolled** in each,
through to completion. A program carries a provider, an optional date window and a
seat capacity; its lifecycle (**upcoming → ongoing → completed**) is **derived from
its dates**. Enrollments move `enrolled → completed | dropped`, capturing a completion
score. Programs are created in-module (there is no Company-Setup config). Data model
is ERD §8 (the training side); everything is tenant-scoped (ADR 0005). See
[ADR 0013](../decisions/0013-training-and-development.md).

> Status: **Active** · Route prefix: `/training`
> Sidebar: Workforce → Training & Development (gated by `training.view`)

## Surfaces

- **`/training`** — the **overview**: stat tiles (ongoing, upcoming, active
  enrollments, completions) and a **table of programs** (name and provider, schedule,
  seats, completed, status). It can be searched, filtered by status and sorted. A row
  opens the program. **Archived (n)** is one more option in the status filter, and
  there a row's menu restores the program or deletes it permanently. HR can **create a
  program** and **export** the list.
- **`/training/{program}`** — a **program's roster**: a header with the provider,
  schedule, status and description, then five stat tiles (seats taken, completion
  rate, average score, at risk, dropped). After that comes the **roster table**
  (status, score, enrolled and completed dates), which can be searched, filtered,
  sorted and paged, with multi-select bulk actions (mark completed / dropped, remove).
  A row opens the enrollment. The AI insights panel sits under the roster. HR can **enroll an employee**, **edit**
  an enrollment (status / score / remarks), **remove** one, and **edit** or **archive**
  the program itself.
- **Employee detail → Training tab** — a read-only summary of an employee's program
  enrollments (managed from this module, not the employee record).

Both pages use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)).

## Derived lifecycle & seats

A program has **no stored status**: it is `completed` once its end date has passed,
`ongoing` once its start date has arrived, otherwise `upcoming` (a program with no
start date reads as upcoming). **Seats taken** counts non-dropped enrollments; a program
with a `capacity` is **full** when that count reaches it (uncapped programs are never
full, and enrolling into a full program is blocked). `completed_at` is set automatically
when an enrollment is marked completed and cleared otherwise.

## Permissions

`training.view` (overview & rosters), `training.manage` (create / edit / archive
programs, enroll, grade, remove enrollments). Built-in **HR Manager** gets both.

## Where the rules live

`App\Support\Training\TrainingWorkflow` is the one path that changes a program or
its roster: create, edit and archive a program; enroll (eligibility and capacity,
reported through `EnrollmentOutcome`); grade (status / score / remarks, with
`completed_at` following the status); bulk actions; remove. Both controllers are thin
callers of it, and so is the assistant, so the toasts and the rules are the same
however a change arrives. A program's effectiveness figures are
`TrainingProgram::analytics()`, shared by the program screen, the AI read and the
assistant.

Enrollment ids are validated against the current workspace (`TenantRule`), so an id
from another organisation is a validation error rather than a silent skip.

## The assistant

`App\Services\Assistant\Modules\TrainingModule` puts training in the chat assistant
([ADR 0050](../decisions/0050-assistant-training-awards-and-events.md)).

- **Reads** (`training.view`):
  - `find_training_programs` — by name or provider, and by derived status;
  - `get_training_program` — schedule, seats, the outcome counts, the average score,
    and by name who is still enrolled after the program ended and who dropped;
  - `find_training_enrollments` — one person's trainings, or one program's roster;
  - `training_summary` — the overview: programs by status, people enrolled,
    completions in the last year, and ended programs with people still enrolled.
- **Writes** (`training.manage`), all through `TrainingWorkflow`:
  - `create_training_program` and `update_training_program` — the values are checked
    against `TrainingProgramRequest`'s own rules. An update is checked as the whole
    program would be, so moving only the end date is still checked against the start.
    A capacity of 0 removes the cap. A name that already exists is refused, so a
    repeated request does not make a second program;
  - `enroll_in_training` — up to 25 people by name. Every name must resolve to exactly
    one person, or nobody is enrolled;
  - `update_training_enrollment` — status, score (0–100) or remarks;
  - `remove_from_training` (the person's score and remarks go with them) and
    `archive_training_program` always wait for the user's **Confirm**.
- **Retrieval:**
  - a question about a person carries their enrollments and how each ended;
  - a question about training that names nobody ("how are our trainings going?")
    carries the overview.
- **No self-service.** As on the screens, training needs `training.view`, including
  one's own.

## Out of scope (this cut)

Per-session calendars & attendance, certificates and expiry tracking, training
budgets / cost, training-needs analysis from performance gaps, and employee
self-enrollment.
