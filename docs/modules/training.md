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

- **`/training`** — the **overview**: a KPI bar (ongoing, upcoming, active
  enrollments, completions) and **program cards grouped by status** (Ongoing /
  Upcoming / Completed), each showing the provider, schedule, seat usage and
  completion count. HR can **create a program** here, and toggle **Archived** to
  restore or permanently delete past programs.
- **`/training/{program}`** — a **program's roster**: a header with the provider,
  schedule, status, seat/completion strip and description, then the list of enrolled
  employees (status, score, completion date). HR can **enroll an employee**, **edit**
  an enrollment (status / score / remarks), **remove** one, and **edit** or **archive**
  the program itself.
- **Employee detail → Training tab** — a read-only summary of an employee's program
  enrollments (managed from this module, not the employee record).

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
