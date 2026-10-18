# Training & Development

Run the organisation's **training programs** and track **who is enrolled** in each,
through to completion. A program carries a provider, an optional date window and a
seat capacity; its lifecycle (**upcoming → ongoing → completed**) is **derived from
its dates**. Enrollments move `enrolled → completed | dropped`, capturing a completion
score. Programs are created in-module (there is no Company-Setup config). Data model
is ERD §8 (the training side); everything is tenant-scoped (ADR 0005). See
[ADR 0013](../decisions/0013-training-and-development.md).

Since [ADR 0074](../decisions/0074-training-self-enrolment-sessions-and-attendance.md),
employees **join programs** themselves (or ask to), and programs meet in **sessions**
with **attendance**. Since
[ADR 0075](../decisions/0075-training-certificates-with-expiry-and-budgets-with-costs.md),
completing a program can earn a **certificate that expires**, and what training costs
is recorded against **budgets**. Since
[ADR 0094](../decisions/0094-training-in-the-app.md), people take part from the
**mobile app** too.

> Status: **Active** · Route prefix: `/training`
> Sidebar: Workforce → Training & Development (for `training.view` or `training.participate`)

## Sections

One sidebar entry. `TrainingNav` (`ModuleNav`) in each page header has two groups,
each item shown to whoever may open it:

- **My training · Browse** — for `training.participate`;
- **Programs** (with the number of requests to join waiting) **· Schedule ·
  Certificates · Budget** — for `training.view`.

`/training` sends someone who only takes part to `/training/me`. The Staff dashboard
has **My training**.

## Surfaces

- **`/training`** — **Programs**: stat tiles (ongoing, upcoming, active enrollments,
  completions), the **requests to join** waiting (each with Approve / Decline, for
  `training.manage`), and a **table of programs** (name and provider — with "open to
  join", "join on approval", a certificate mark and requests waiting — schedule,
  seats, completed, status). It can be searched, filtered by status and sorted. A row
  opens the program. **Archived (n)** is one more option in the status filter, and
  there a row's menu restores the program or deletes it permanently. HR can **create a
  program** and **export** the list.
- **`/training/{program}`** — a **program's page**: a header with the provider,
  schedule, status, who can join, its certificate, its budget and description; six stat
  tiles (seats taken, completion rate, average score, attendance, at risk, dropped);
  then underline tabs (`?tab=` opens one):
  - **Roster** — requests to join waiting above it; the **roster table** (status with a
    certificate mark, score, attendance, enrolled and completed dates), searched,
    filtered, sorted and paged, with multi-select bulk actions (mark completed /
    dropped, remove). A row opens the enrollment. The AI insights panel sits under it.
  - **Sessions** — each session's day, time, title, place and the attendance taken
    ("8 of 10 came", "Not taken", "Upcoming"). HR schedules (optionally repeating),
    edits and cancels sessions, and **takes attendance** from a session's day on.
  - **Costs** — the budget against the spend, spend per participant and per
    completion, where the money went, and the costs (record, edit, remove).
  HR can **enroll an employee**, **edit** an enrollment (status / score / remarks),
  **remove** one, and **edit** or **archive** the program itself.
- **`/training/me`** — **My training**: underline tabs **Coming up** (the person's
  sessions as an agenda, each with *Add to calendar*; *Subscribe* for those who also
  answer invitations), **Programs** (each with attendance, score and completion; a
  request waiting or declined with HR's note; *Withdraw request* / *Leave program*
  where allowed) and **Certificates**.
- **`/training/catalog`** — **Browse**: programs open to sign-ups that are not over,
  soonest first, each with its dates, sessions, seats left and certificate, and
  **Join** / **Ask to join** (or where the person stands). A program here is a
  participant's view of it (`MyTraining::listing()`): never its budget or AI notes.
- **`/training/schedule`** — **Schedule**: every session in a month (`?month=YYYY-MM`),
  Monday first, filterable by program. A held session with no attendance has an amber
  dot; programs running that month with no session are listed below. On a phone the
  month reads as an agenda.
- **`/training/certificates`** — **Certificates**: tiles (valid, expiring within 60 days,
  expired, renewed), the register searched / filtered by standing / sorted, **Export**,
  and per row *View certificate* and, for `training.manage` on an expiring or expired
  one, *Renew by enrolling again*.
- **`/training/certificates/{id}`** — one certificate laid out to print (landscape),
  for HR and for its holder.
- **`/training/budget`** — **Budget**: one year (`?year=`): tiles (budget, spent,
  remaining or over, given to programs starting that year), the year's spend against
  its budget, spend by category and by month, programs with their own budget against
  their spend, and the year's costs. HR sets the year's budget.
- **Employee detail → Training tab** — a read-only summary of an employee's program
  enrollments (managed from this module, not the employee record). Their
  **Certifications** tab lists training certificates marked "from training"; those
  cannot be removed there.

The list pages use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)); forms use
the shared modal kit.

## Derived lifecycle & seats

A program has **no stored status**: it is `completed` once its end date has passed,
`ongoing` once its start date has arrived, otherwise `upcoming` (a program with no
start date reads as upcoming). **Seats taken** counts enrolled and completed people (a
request takes no seat); a program with a `capacity` is **full** when that count reaches
it (uncapped programs are never full, and enrolling into a full program is blocked).
`completed_at` is set automatically when an enrollment is marked completed and cleared
otherwise.

## Joining and requests (ADR 0074)

`enrollment_mode` says who may join: **closed** (HR enrolls — the default), **open**
(take a seat at once while seats last) or **approval** (ask; HR approves if a seat is
left, or declines with a note). Nobody joins a program that is over or archived.

A request is an enrollment row with status `requested`, decided to `enrolled` or
`declined`. It is not on the roster: not counted, not graded, not bulk-changed. HR's
**Enroll** takes a request (or a declined one) in on the same row. A person withdraws a
request any time, and gives up a seat only while the program is upcoming and before any
attendance is taken.

Told: HR (`training.manage`) when someone asks; the person when approved, declined or
enrolled by HR.

## Sessions & attendance (ADR 0074)

A session is a day and local start / end times on the organisation's clock, inside the
program's dates. Adding one can repeat it daily (or on weekdays) up to a date — 60 at
a time, 200 per program. A program's dates cannot move off a session it has.

Attendance is taken from the session's day on, for the people holding a seat:
**present** and **late** count as attended, **absent** against, **excused** is left out.
A person's rate is attended ÷ counted. Cancelling a session removes its marks.

A person's sessions are in their calendar feed (ADR 0070) and downloadable one by one.

## Certificates (ADR 0075)

A program that **issues certificates** gives one to each person marked completed: an
`employee_certifications` row tied to the enrollment, numbered `TC-<year>-<id>`, dated
the day they completed and expiring after the program's validity in months (or never).
Undoing the completion or removing the person withdraws it; turning certificates on
issues them to everyone who had completed. Standing is derived: valid, **expiring**
(within 60 days), expired, or **renewed** (the same person later earned one of the
same name). `training:certificate-reminders` (daily) tells each holder once, and HR
with a count.

## Budget & costs (ADR 0075)

A program's `budget`; costs recorded on a program (category, amount, the day it was
incurred); and a budget per calendar year. Spend is always the sum of the costs. A
year counts the costs incurred in it, archived programs included.

## Permissions

- `training.view` — Programs, a program's page, Schedule, Certificates, Budget.
- `training.manage` — create / edit / archive programs, enroll, grade, remove; decide
  requests; schedule sessions and take attendance; record costs and set budgets.
- `training.participate` — My training, Browse, joining / asking / withdrawing, one's
  own session downloads and certificates.

Built-in **HR Manager** gets all three; **Department Head** gets `training.view` and
`training.participate`; **Staff** gets `training.participate`.

## Where the rules live

All in `App\Support\Training`; the controllers and the assistant are thin callers, so
the toasts and the rules are the same however a change arrives. Refusals are
`TrainingException`s, with the form field they belong to when there is one.

- `TrainingWorkflow` — create, edit and archive a program; enroll (eligibility and
  capacity under a lock, reported through `EnrollmentOutcome`, requests taken in);
  grade; bulk actions; remove. It keeps certificates in step and refuses dates that
  strand a session.
- `TrainingSelfService` — join, ask, withdraw, approve, decline.
- `TrainingSessions` — schedule, edit, cancel; take attendance; attendance rates.
- `TrainingCertificates` — issue, withdraw, backfill; standings; reminders; the
  register query.
- `TrainingBudgets` — record, edit and remove costs; set a year's budget; a program's
  and a year's money.

A program's effectiveness figures are `TrainingProgram::analytics()` (roster only),
shared by the program screen, the AI read and the assistant.

Enrollment ids are validated against the current workspace (`TenantRule`), so an id
from another organisation is a validation error rather than a silent skip. A person's
own enrollment, session download or certificate is a 404 for anyone else.

## The assistant

`App\Services\Assistant\Modules\TrainingModule` puts training in the chat assistant
([ADR 0050](../decisions/0050-assistant-training-awards-and-events.md)). It is offered
to holders of `training.view` or `training.participate`, with only the tools each may
run († waits for the user's **Confirm**).

- **Reads** (`training.view`):
  - `find_training_programs` — by name or provider, and by derived status;
  - `get_training_program` — schedule, who can join (and requests waiting), seats, the
    outcome counts, the average score, sessions and attendance, the certificate, spend
    against budget, and by name who is still enrolled after the program ended and who
    dropped;
  - `find_training_enrollments` — one person's trainings, or one program's roster;
  - `training_summary` — the overview: programs by status, people enrolled,
    completions in the last year, ended programs with people still enrolled, requests
    waiting, certificates expiring or expired, and this year's spend;
  - `find_training_requests`, `find_training_sessions` (by program or dates; the next
    30 days by default), `find_training_certificates` (by person or standing),
    `get_training_budget` (a year, or a program).
- **Writes** (`training.manage`):
  - `create_training_program` and `update_training_program` — the values are checked
    against `TrainingProgramRequest`'s own rules, including who can join, the
    certificate (a validity of 0 means it never expires) and the budget. An update is
    checked as the whole program would be. A name that already exists is refused;
  - `enroll_in_training` — up to 25 people by name; every name must resolve to exactly
    one person, or nobody is enrolled;
  - `update_training_enrollment` — status (roster statuses only), score (0–100) or
    remarks;
  - `schedule_training_session` (optionally repeating), `record_training_attendance`
    (present / late / absent / excused by name, and "everyone else"),
    `record_training_cost`, `set_training_budget`;
  - `decide_training_request`†, `remove_from_training`† and
    `archive_training_program`†.
- **One's own** (`training.participate`): `find_my_training`, `find_open_trainings`,
  `join_training`† and `leave_training`†.
- **Retrieval:**
  - a question about a person carries their enrollments, how each ended, and their
    certificates;
  - a question about training that names nobody carries the overview.

## In the app (ADR 0094)

The mobile app's **Learning** family (Services) has **My training**, **Browse** and
**Certificates**, on `GET /api/training`, `/api/training/catalog`,
`/api/training/programs/{hashid}` (with `join` and, on an enrollment, `DELETE
/api/training/enrollments/{id}`), a 10-minute signed `.ics` link per session, and
`/api/training/certificates/{id}`. All are behind `training.participate`, self-scoped, and
read through `App\Queries\MyTraining`, the same reading the web's My training and Browse
use. Changes go through `TrainingSelfService` and are logged "via the app". The sessions a
person holds a seat at also show on the app's **My schedule** (at their site's hour), and
their programs and certificates are found by the app's **Search**. See the
[Mobile app](./mobile-app.md) doc for the screens.

## Out of scope

Training-needs analysis from performance gaps; rooms for sessions (booked through
Events' room booking); session reminders; waitlists; budgets per department and spend
approval; reminders for certifications typed in on the profile; running training
(approving, grading, attendance) from the mobile app.
