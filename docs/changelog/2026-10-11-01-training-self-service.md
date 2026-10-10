# Training: employees join programs, sessions with attendance, certificates that expire, budgets and costs

Training had one user: HR. Employees couldn't see what was on offer or join it, a
program was a date window with no record of who came, completing one earned nothing a
person could show, and nothing tracked what training cost. Now everyone takes part, and
the program is run end to end. See
[ADR 0074](../decisions/0074-training-self-enrolment-sessions-and-attendance.md) and
[ADR 0075](../decisions/0075-training-certificates-with-expiry-and-budgets-with-costs.md).

## Highlights

- **Employees join training.**
  - A program says who can join: **HR enrolls people**, **Anyone can join** (a seat at
    once, while seats last) or **Anyone can ask to join** (HR approves or declines).
  - **Browse** lists the programs open to sign-ups: dates, sessions, seats left and the
    certificate each earns.
  - Requests wait at the top of **Programs** and of the program's roster. **Approve**
    takes a seat if one is left; **Decline** takes an optional note the person sees.
    HR's **Enroll** takes a request in too. Everyone is told what was decided, and
    people HR enrolls are told as well.
  - A person withdraws a request any time, and leaves a program only before it starts.
- **My training**: sessions coming up as an agenda (each with *Add to calendar*), the
  person's programs with attendance and score, and their certificates.
- **Sessions and attendance.**
  - A program meets in sessions: a day, local start and end times, a title and a
    place. **Repeat daily** (or on weekdays) adds a run of them at once.
  - **Take attendance** from a session's day on: present, late, absent or excused.
    Each person's rate shows on the roster, the program's tiles and My training.
  - **Schedule** shows every session in a month. A held session with no attendance is
    flagged, and programs running with no session are listed.
  - Sessions appear in the calendar people subscribed to from My invitations.
- **Certificates that expire.**
  - A program can earn a certificate valid for some months. Marking someone completed
    issues one — numbered, dated, and on their profile's Certifications tab. Undoing
    the completion withdraws it. Turning certificates on issues them to everyone who
    already finished.
  - **Certificates** lists them as valid, expiring within 60 days, expired, or renewed
    by a later one. Expiring ones can be renewed by enrolling the holder again.
  - Holders are told once, 60 days before a certificate expires, and so is HR.
  - A certificate prints on a landscape page, for HR and for its holder.
- **Budget and costs.**
  - A program has a budget, and costs are recorded against it: trainer fees, venue,
    materials, travel, meals, certification.
  - The program's **Costs** tab shows spend against budget, per participant and per
    completion.
  - **Budget** sets the year's training budget and shows the spend by category, by
    month and by program, with every cost behind it.
- **One way in.** Training & Development is one sidebar entry for anyone who runs or
  takes part in training. Its sections are **My training · Browse ‖ Programs (requests
  waiting) · Schedule · Certificates · Budget**. The Staff dashboard gains **My
  training**.

## Server

- New permission `training.participate`, back-filled to the built-in Staff,
  Department Head and HR Manager roles.
- Migration `2026_10_11_000000_create_training_self_service_tables`:
  - adds `training_sessions`, `training_attendances`, `training_costs` and
    `training_budgets`;
  - adds `enrollment_mode`, `issues_certificate`, `certificate_validity_months` and
    `budget` to `training_programs`;
  - adds `decided_by`, `decided_at` and `decision_note` to `training_enrollments`;
  - adds `training_enrollment_id`, `certificate_no` and `expiry_notified_at` to
    `employee_certifications`.
- Enrollment statuses gain `requested` and `declined`. Every roster figure now counts
  the roster only (`onRoster`, `active` = enrolled or completed): the analytics, the
  counts, both exports and the assistant.
- `App\Support\Training` gains `TrainingSelfService`, `TrainingSessions`,
  `TrainingCertificates`, `TrainingBudgets` and `TrainingException`. `TrainingWorkflow`
  counts seats under a lock, takes requests in on enroll, refuses to grade a request,
  keeps certificates in step and refuses dates that strand a session.
- New command `training:certificate-reminders`, scheduled daily.
- `EventCalendar` writes training sessions, and the calendar feed carries the ones a
  person holds a seat on.
- The employee profile refuses to remove a training certificate, on the screen and in
  chat.
- Routes under `/training`: `me`, `catalog`, `schedule`, `certificates`, `budget`,
  plus session, attendance, cost and request-decision endpoints — all declared before
  the `{trainingProgram}` wildcard. `/training` sends someone who only takes part to
  `/training/me`.
- The exports: the program list adds who can join, the certificate, sessions, budget
  and spend; the roster adds attendance and the certificate number; a new certificate
  register export.
- The assistant gains 13 tools:
  - HR: `find_training_requests`, `decide_training_request`†,
    `find_training_sessions`, `schedule_training_session`,
    `record_training_attendance`, `find_training_certificates`,
    `get_training_budget`, `record_training_cost` and `set_training_budget`;
  - participants: `find_my_training`, `find_open_trainings`, `join_training`† and
    `leave_training`†.

  Program tools take who can join, the certificate and the budget;
  `get_training_program`, the training picture and a person's brief carry sessions,
  certificates and money. († waits for Confirm.)
- `SystemGuide` gains Training schedule, Training certificates, Training budget and My
  training. The router knows sessions, attendance, certificates and budgets.
- Data Export covers the four new tables.
- The seeder adds, dated from the day it runs: programs open to join and on approval
  (with requests waiting and one declined), sessions with attendance, certificates
  that are valid, expiring and expired, costs and this year's budget. The staff login
  (earlkian8) holds a seat with sessions coming up, has a certificate expiring soon,
  and can join or ask to join two more.

## Frontend

- New pages under `training/`: `me`, `catalog`, `schedule`, `certificates`,
  `certificate` and `budget`.
- The program page gains underline tabs (Roster, Sessions, Costs), an attendance tile
  and column, a certificate mark, and the requests to join.
- The program form moves from a side sheet to the shared modal kit, with sections for
  who can join, the certificate and the budget.
- New components: `TrainingNav`, `RequestsCard`, `SessionsPanel`, `SessionFormModal`,
  `AttendanceModal`, `CostsPanel`, `CostFormModal`, `BudgetMeter` and `MonthColumns`.
- The shared `BarList` takes a `format` (used for money). The employee profile marks
  training certificates and links them to their printable page. The Subscribe dialog
  mentions training sessions.

## Docs

- ADRs 0074 and 0075.
- `modules/training.md` (rewritten); `modules/assistant.md`, `data-export.md`,
  `help-center.md`, `events.md` and `employees.md`.
- `database/training-tables.md` (four tables and the new columns),
  `employees-tables.md` and ERD §8 and §3.
- Help: new *Your training*, *Training sessions and attendance*, *Training
  certificates* and *Training budget and costs*; *Training programs* updated.
- `not-yet-built.md`:
  - removed: self-enrolment, session calendars and attendance, certificates and expiry
    tracking, budgets and cost;
  - still listed: training-needs analysis;
  - added: rooms for sessions, session reminders and waitlists, department budgets and
    spend approval, reminders for profile certifications, and training on the mobile
    app.

## Notes

- **Verified:** Pest (count in the commit message), Pint, tsc, ESLint, Prettier and
  the build. The migration rolls back and re-applies on a scratch database, and its
  columns and indexes were checked against the table doc. HR and Staff were checked in
  headless Chromium at 1440 and 390 px.
- Docker was not built or run. Gemini was never called: the key stays empty and the
  tests use the stub models.
- Sessions are judged on the organisation's clock. A program's own status still uses
  the server's date, as before.
