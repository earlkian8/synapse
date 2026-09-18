# Each company decides how its days are judged

Until now, attendance judged every company's day the same way. Overtime was whatever was
worked past the day's hours, it never needed approval, and it counted an early clock-in.
Nothing was rounded. Lateness was never serious enough to cost a half day. A lunch nobody
punched was paid work. And nothing split night, rest-day or holiday work apart for payroll
to read.

This is Phase 2 of making attendance fit any company (ADR 0038). A company now picks a
preset for how its days are judged and adjusts it through plain options. Every day keeps
the rules it was judged by, and comes out as the minute buckets payroll needs.

## Highlights

- **Attendance Policies**, a new Company Setup screen. Start from a preset — *Philippines —
  Labor Code*, *Standard 40-hour week*, *Flexible, no lateness* or *Shift work* — or from
  the built-in rules, then adjust. The options are grouped the way HR thinks about a day:
  lateness, short days, rounding, breaks, overtime, night differential, when punches
  count. A closed group still says what it does ("1h unpaid after 5h if none is punched").
- **A worked example beside the options.** A sample day is judged by the rules on screen
  before they are saved. A strip shows the shift, the time on the clock, a break, the
  night window and what ran past the shift. A ledger says what the day comes out as:
  regular, overtime and whether it awaits approval, late and how much grace forgave,
  short, the break and whether it was deducted, and night, rest-day and holiday minutes.
  It is the server's own evaluator answering, so the example cannot disagree with the
  board.
- **What a company can decide:** a daily grace or a **monthly allowance** (the fourth late
  day of the month is the one past it); when lateness or a short day becomes a **half
  day** or an absence; rounding to the nearest, up or down 5/10/15/30 minutes; paid
  breaks, an **unpaid lunch deducted when none is punched**, and a longest break;
  overtime **daily, weekly or both** (without counting a minute twice), with a minimum
  block, **approval**, and whether an early clock-in counts; and a **night window**.
- **Each day keeps its policy.** Editing a policy changes how days are judged from then on.
  **Re-apply rules** on the board is still the one deliberate way to re-judge a recorded
  day.
- **Minutes a payroll can read.** Every day is split into **regular** and **overtime**
  (with **approved overtime** apart). **Night**, **rest-day** and **holiday** minutes are
  tagged over those same minutes, never added to them. The monthly report gains a
  **Payroll summary** download: one row per employee, every figure in whole minutes.
- **The board shows the verdict.** The day modal names the policy the day was judged by.
  Its totals band adds regular minutes, overtime awaiting approval, and night, rest-day
  and holiday minutes when there are any. Chips give the reasons ("Half day", "Unpunched
  break deducted", "Break ran over"). **Half day** is a new status, with its own filter,
  exceptions group and monthly column.
- **The setup wizard has a new Attendance step** after Leave. Pick a preset, customise it
  in the same editor if you like, and set up the hours most people work. It becomes the
  company default, so it applies from the first punch. The wizard is now six steps.

## Backend

- **New table** `attendance_policies` (`…_create_attendance_policies`): name, description,
  `preset_key`, `settings` JSON, `settings_version`, `is_default` (one per company), soft
  deletes. A policy is named on `employee_schedule_assignments`, `work_schedules` or
  `departments` (new nullable `attendance_policy_id` on each).
- **`attendance_records` gains** `regular_minutes`, `approved_overtime_minutes`,
  `night_minutes`, `rest_day_minutes`, `holiday_minutes`, `excused_late_minutes` and
  `flags`. The migration back-fills regular (worked − overtime) and approved overtime (all
  of it — none ever needed approval); `attendance:recompute` fills the rest.
- **`AttendancePolicySettings`** — the typed, readonly settings, giving every missing key
  its default. **`AttendancePolicyPresets`** — the four presets, and the one place
  Philippine defaults live.
- **`PolicyResolver`** — **assignment → schedule → department → company default →
  built-in fallback**. `forMany()` takes five queries for a roster over any range, and
  **one** for a company with no policies.
- **`AttendanceCalculator::evaluate()`** — a pure evaluator returning a **`DayResult`**,
  fed a **`DayContext`**. The order: round → pair into intervals → clip early minutes →
  breaks → late and undertime → buckets → thresholds → status and flags. Rounding and the
  night window are read on the company's own clock. `recompute()` becomes a thin adapter.
- **`DayRules` is `version: 3`**: it carries the policy's id, name, source and complete
  settings. Version 1 and 2 snapshots read as the built-in fallback.
- **The built-in fallback reproduces the old numbers exactly.** Grace and the overtime
  threshold defer to the schedule when a policy leaves them unset. A test replays every
  seeded demo day through a verbatim copy of the old calculator. On the dev database, all
  680 recorded days recompute with no status or total moving.
- **The punch windows are the policy's.** The four-hour early clock-in and sixteen-hour
  shift span (ADR 0036's constants) are now settings with those defaults. An open shift
  claims punches for as long as its own policy allows.
- **Weekly overtime and a monthly allowance read the days before each one.** The context
  queries them only when the policy uses them. Re-apply and `attendance:recompute` now
  walk days in date order.
- **`AttendancePolicyRequest`** is the one place settings are validated: the editor, the
  wizard and the worked example all use it, and thresholds that contradict each other are
  refused. What is stored is always complete and canonical.
- **HTTP:** `GET/POST /setup/attendance-policies`, `POST …/{policy}`,
  `PATCH …/{policy}/default`, archive, restore, force delete (refused while anything names
  it), and `POST …/preview`, which judges a sample day and writes nothing. It answers
  validation errors as JSON. `POST /setup/wizard/attendance`. Every write is
  activity-logged.
- **The payroll period summary:** `GET /attendance/export?tab=period` (the month, or
  `from`–`to` up to 62 days). The daily and monthly CSVs gain the bucket columns.
- New permissions **`setup.attendance-policies.view`** and **`.manage`**. The wizard's
  Attendance step also needs `setup.schedule.manage` to write the default schedule.
- **Everything that counts statuses knows `half_day`:** the board's filters, statistics,
  the dashboard, the monthly report, award scoring (half of a present day) and the mobile
  API's summary, which also gains the buckets. The assistant's brief reports overtime
  awaiting approval, half days, night, rest-day and holiday minutes, and breaks that ran
  over.

## Frontend

- **`features/attendance-policy-config`**: the page, the editor modal, `PolicySections`
  (the grouped options) and `WorkedExample`, whose hook is debounced and abortable, and
  never sets state in an effect.
- **The schedule editor, the department form and both assign dialogs** can name a policy.
  The employee profile's schedule history shows one that singles someone out.
- **Attendance board:** the day modal's policy line, bucket totals and flag chips; half
  days in the log, the exceptions panel, the weekly legend and the monthly report; the
  night column and "awaiting approval" note; the **Payroll summary** button;
  **Re-apply rules**.
- **Setup wizard:** the Attendance step, reusing the editor's sections and worked example.
- **Mobile app:** the `half_day` status, its colour, and the summary's bucket fields.

## Notes

- **Declared now, enforced later.** The missing clock-out rules and the capture rules
  (allowed sources, selfie, geofence, office networks) are validated and saved, and the
  editor says so. The end-of-day job and capture enforcement come with Phase 4.
- **Overtime that needs approval stays unapproved** until Phase 3's approval flow. Its
  flag and the "awaiting approval" notes say how much.
- **A schedule keeps its grace.** Moving grace onto the policy outright would have changed
  every existing tenant's lateness. A policy's grace wins when it sets one, and the
  schedule editor says so.
- **Correcting an earlier day does not re-judge later ones by itself.** Under weekly
  overtime or a monthly allowance, re-apply the week or month.
- **After deploying**, run `php artisan attendance:recompute` to fill the new buckets for
  days already recorded.
