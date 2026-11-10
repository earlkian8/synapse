# Check-ins

Once a month, every employee answers six quick questions about how work is going:
mood, job satisfaction, engagement (UWES-3) and how often they have considered
leaving. Answers are **confidential**: nobody at the company reads one person's; HR and
department heads see results for groups of five or more. Data model is ERD §9 ([check-ins
tables](../database/check-ins-tables.md)); everything is tenant-scoped (ADR 0005). See
[ADR 0102](../decisions/0102-confidential-mood-check-ins-and-what-only-your-records-hold.md).

> Status: **Active** · Route prefix: `/check-ins` · API: `/api/check-ins`
> Sidebar: Workforce → Check-ins (shown with `check-ins.view` **or** `check-ins.respond`;
> `/check-ins` sends viewers to Team results and everyone else to My check-in)
> Mobile: Services → How you're doing → Check-in, and a Home card while the month's waits

## Surfaces

The pages share a section control (`CheckInsNav` on `ModuleNav`): **My check-in** for
`check-ins.respond` ‖ **Team results** for `check-ins.view`.

- **`/check-ins/me`**: this month's check-in, one question at a time, beside a drawn
  face that wears the mood answer (and only that answer). Choosing moves on; number keys
  answer; the last step reads every answer back, with a private note (encrypted, owner
  only), before **Save check-in**. Once answered, the month as it was told, with
  **Change my answers** until the month ends. Below, **Your year**: twelve faces, oldest
  first, a month without a check-in in outline; choosing one shows that month. Beside
  it, *Who sees your check-in*.
- **`/check-ins/results`**: a month picker; tiles for answered (of active employees),
  mood (1–5), engagement (0–6) and the share who often or always think of leaving; **mood
  over the year** as 100% stacked columns (an outlined month had fewer than five
  answers); three small single-scale line charts (satisfaction, engagement, often think
  of leaving); **by team**, sorted by name. HR with `check-ins.manage` has the **Monthly
  check-ins** on/off switch.
- **Mobile**: `app/check-in/index.tsx` (this month on the hero card, the year of faces,
  a month's answers, who sees it) and `app/check-in/answer.tsx` (the questions as a
  modal, one per page, then the read-back and note). Home shows five faces while the
  month's check-in waits (`check_in_due` on the session); tapping one opens the
  questions with that mood chosen.

## How it works

`App\Support\CheckIns`:

- `CheckInQuestions`: the six questions, their scales and validation, defined once for
  the web, the app and the request (`CheckInRequest`).
- `CheckInPeriod`: a month is the organisation's (`OrganizationClock`), named by its
  first day.
- `CheckInWorkflow`: `submit` (one row per employee per month; answering again changes
  it; refused when check-ins are off or the person isn't currently employed),
  `overview` (what the person sees) and `due`. Not activity-logged.
- `CheckInResults`: `period` (company, departments and the pooled "Other teams"),
  `trend` (twelve months) and `teamClimate` (each department's latest shown result in
  the last three months). A group is shown only with `MIN_GROUP` = 5 answers. Hidden
  departments are pooled, and the smallest shown one joins a pool still under five, so
  no hidden group is a difference of shown ones.
- `CheckInReminders` and `check-ins:remind` (daily 09:00): on the organisation's 1st,
  "Your {month} check-in is open"; on its 20th, a reminder to whoever hasn't answered.

## What it feeds

Each department's latest shown result (`teamClimate`), never anyone's own answers, is
stored with every attrition risk score as `team_mood`, `team_engagement` and
`team_leave_thoughts`, beside `months_since_recognition` and `leave_days_365d`
(`AttritionRiskAssessor::SIGNAL_FEATURES`). The general model can't read them; an
organisation's own model tries them and keeps them only if they make it more accurate
(see [Attrition Risk](./attrition-risk.md) and [Model graduation](./model-graduation.md)).
The attrition breakdown reads them as the *team mood*, *recognition gap* and *rest*
indicators.

## Permissions

`check-ins.respond` (answer your own: every built-in role), `check-ins.view` (team
results: HR Manager, Department Head), `check-ins.manage` (the on/off switch: HR
Manager).

## Confidentiality

No assistant tool reads check-ins; the Data Export excludes `check_ins`; `note` is
encrypted and hidden from serialisation; the results props carry counts and means only
(a test holds that no employee name or id reaches them). Confidential, not anonymous:
the row names its employee so the person can see and change their own months.
