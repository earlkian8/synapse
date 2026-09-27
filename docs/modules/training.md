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

## Out of scope (this cut)

Per-session calendars & attendance, certificates and expiry tracking, training
budgets / cost, training-needs analysis from performance gaps, employee self-enrollment,
and an assistant capability.
