# 0038 — Attendance policies: presets and typed options, snapshotted per day; minutes, not money

- **Status:** Accepted
- **Date:** 2026-09-18
- **Builds on:** [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md),
  [0037 — Schedules are templates, assignments are dated, a resolver decides the day](./0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
- **Related:** [Attendance policies module](../modules/attendance-policies.md),
  [Attendance module](../modules/attendance.md),
  [attendance tables](../database/attendance-tables.md),
  [0019 — Payroll and benefits removed](./0019-remove-payroll-and-benefits.md),
  [0034 — A company writes its own setup](./0034-a-company-writes-its-own-setup.md)

## Context

After ADR 0037 the module knew *which* shift applied to a person on a date. *How that
day was judged* was still hardcoded in `AttendanceCalculator::recompute()`, with two
knobs on the schedule (`grace_minutes`, required hours):

- overtime was `worked − required`, daily only, never needed approval, and counted an
  early clock-in;
- nothing was rounded; no lateness was ever severe enough to cost a half day;
- a break was whatever was punched — an unpunched lunch was paid work;
- there was no night, rest-day or holiday split — nothing a payroll system could read.

Every company judges these differently, and a generic HR product cannot ship one
company's rules. Nor can it ship a rule language: a formula editor is a support burden
and a correctness hazard, and the setup wizard already settled on "choose one, then make
it yours" (ADR 0034).

## Decision

- **A policy is a preset plus typed options.** `attendance_policies` holds a name, the
  `preset_key` it was made from, a `settings` JSON document and its `settings_version`,
  and `is_default` (one per organisation). Archived, never hard-deleted while anything
  names it. `AttendancePolicySettings` is the readonly value object over the JSON; it
  gives every missing or malformed key its default, so a partial document — a preset's
  overrides, an older save — is always a complete policy. The groups: punch windows,
  lateness and grace, undertime, rounding, breaks, overtime, missing clock-out, night
  differential and capture. Nothing outside that list is configurable.

- **Presets are server-defined and keyed** (`AttendancePolicyPresets`):
  `ph_labor_code` (overtime after 8h a day on approval, rest-day and holiday minutes
  bucketed, night differential 22:00–06:00, a 1h unpaid lunch deducted after 5h),
  `standard_40h_week`, `flexible_no_lateness` and `shift_work`. The preset list is the one
  place Philippine defaults live; the engine is generic.

- **The built-in fallback is a policy with nothing set, and it reproduces the old
  behaviour exactly.** Two values defer to the shift when a policy leaves them unset:
  `lateness.grace_minutes` (the schedule's grace) and `overtime.daily_after_minutes` (the
  day's required minutes). Everything else defaults to "off". "An unconfigured tenant's
  numbers do not move" is therefore true by construction, not by a second code path. A
  test replays every seeded demo day through a verbatim copy of the pre-policy calculator
  and asserts identical status, worked, break, late, undertime and overtime; the dev
  database's 680 recorded days recompute with no status or total moving.

- **One resolver, the same shape as the shift chain.** `PolicyResolver` walks
  **assignment → schedule → department → organisation default → fallback**: the policy on
  the dated assignment covering the date (one person judged differently from everyone on
  their shift), then the policy on the schedule the day's shift came from (including one
  a roster override borrowed), then the department's, then the company default. Work
  locations slot in between department and organisation in Phase 4. `forMany()` answers
  for a roster over a range in five queries whatever the range, and in **one** for a
  company that has no policies — every company until it configures one.

- **The calculator becomes a pure day evaluator.**
  `AttendanceCalculator::evaluate(punches, DayRules, DayContext): DayResult` never queries.
  The caller gathers a `DayContext`: the shift's instants, the organisation's zone
  (rounding and the night window are read on the **local** clock), approved leave, and —
  only when the policy needs them — the week's regular minutes before the day (weekly
  overtime) and the lateness the month has already forgiven (a monthly grace allowance).
  The order of operations is documented in the class and tested as a pipeline: round →
  pair punches into work and break intervals → clip early minutes → breaks (pay the paid
  part, deduct an unpunched one, note an over-long one) → late and undertime → buckets →
  thresholds → status and flags. `recompute()` is a thin adapter that writes a
  `DayResult` onto a record.

- **Minutes, not money.** A day comes out as buckets: `regular + overtime = worked` is a
  partition; `night`, `rest_day` and `holiday` are **tags over those same minutes**,
  because a payroll system multiplies premiums rather than adding them. Overtime under a
  policy that requires approval is computed but not in `approved_overtime_minutes` —
  that waits for Phase 3's approval flow. Attendance never holds a rate (ADR 0019).

- **Statuses stay a short list; everything more specific is a flag.** One new status,
  `half_day` (very late, or very short, by the policy's thresholds). A threshold can also
  make a punched day `absent`. `flags` carries the reasons: `late`, `undertime`,
  `half_day`, `late_absent`, `below_minimum`, `break_deducted`, `break_exceeded`,
  `unapproved_overtime`, `rest_day_worked`, `holiday_worked`. Phase 4 adds capture flags.
  Thresholds apply only to working days: a rest day asks for no hours to fall short of.

- **A day keeps the policy it was judged by.** `DayRules` is `version: 3` in the same
  `rules` column: it adds the policy's id, name, source and complete settings. A version 1
  or 2 snapshot reads as the fallback. Editing a policy changes how days are judged from
  then on; re-applying current rules (per day, or over the period on screen) is the one
  deliberate way a recorded day changes, and it now re-resolves the policy along with the
  shift.

- **The punch windows become the policy's.** ADR 0036's constants — four hours of early
  clock-in, sixteen hours of shift span — are now `punch_windows.early_clock_in_minutes`
  and `punch_windows.max_shift_span_minutes`, defaulting to exactly those values. An open
  shift claims a punch for as long as **its own** snapshot's policy allows.

- **Declared now, enforced later.** `missing_clock_out` (flag / close at shift end /
  close N minutes after) and `capture` (allowed sources, selfie required, geofence mode,
  web IP allowlist) are validated and stored, and shown as such in the editor. The
  end-of-day job and capture enforcement arrive in Phase 4; the calculator only ever reads
  their results.

- **The worked example uses the real evaluator.** The editor's example day is judged by
  `POST /setup/attendance-policies/preview` — the same `evaluate()`, on unsaved punches,
  writing nothing — rather than by a TypeScript copy of the rules. (ADR 0037's schedule
  preview mirrored the resolver in the browser because it runs per keystroke over a
  fortnight; this is one debounced request per pause, and a second copy of the
  day-judging rules would be the larger risk.)

- **Validation lives in one place.** `AttendancePolicyRequest::settingsRules()` and
  `validateSettings()` (thresholds that contradict each other are refused) are used by the
  Company Setup editor, the setup wizard's Attendance step and the worked example. What is
  stored is always the settings as the value object reads them back — complete and
  canonical.

- **Permissions.** `setup.attendance-policies.view` and `setup.attendance-policies.manage`
  (named like the other Company Setup catalogues' abilities rather than the plan's
  `setup.attendance_policy.*`). HR Manager holds both. Naming a policy on an assignment
  goes with the assignment's own permission (`attendance.roster.manage` or
  `employees.update`), on a schedule with `setup.schedule.manage`, on a department with
  `setup.departments.manage`.

- **The setup wizard gains an Attendance step** after Leave: pick a preset (each card
  lists the rules that make it different), optionally customise it in the same editor,
  and optionally write the hours most people work. The policy becomes the company default
  when there is none yet, and the schedule becomes the default schedule on the same
  terms. Writing the schedule additionally needs `setup.schedule.manage`.

- **Payroll reads a period summary.** `GET /attendance/export?tab=period` (the anchor
  month, or `from`–`to` up to 62 days) streams one row per employee with days worked,
  absences, half days, late days, holidays and every bucket in whole minutes. The column
  set is fixed in `AttendanceExportController::PERIOD_COLUMNS` and documented in the
  module doc.

## Consequences

- **A late correction to earlier days does not reach the ones after it by itself.** A
  weekly-overtime or monthly-allowance verdict reads the days before it as they were
  saved. Correcting Monday does not re-judge Friday; re-applying the week (or month) does,
  and re-apply and `attendance:recompute` walk days in date order for exactly this reason.
- **The schedule keeps its grace field.** The plan moved grace onto the policy outright;
  doing that would have changed every existing tenant's lateness. Instead the policy's
  grace wins when it sets one and the schedule's applies otherwise. The schedule editor
  says so.
- **The schedule day's `unpaid_break_minutes` (ADR 0037) is still not read.** Unpunched
  breaks are the policy's `breaks.auto_deduct_*`; tying the two together is a later
  decision.
- **The weekly threshold uses a Monday–Sunday week.** A company whose week starts
  elsewhere cannot say so yet.
- **Rounding and the night window read the organisation's one zone** (ADR 0036). A
  multi-site company in several zones waits for Phase 4's work locations.
- **Existing records get their buckets from `attendance:recompute`.** The migration
  back-fills the two that follow from a record directly — regular as worked less
  overtime, and all overtime as approved, since none ever needed approval — and the
  command fills night, rest-day and holiday minutes, judging every pre-policy snapshot by
  the fallback.
- **Clients that list statuses must know `half_day`.** The web app, the mobile app, the
  board's filters, the dashboard, statistics, award scoring (a half day counts as half of
  a present day) and the assistant's brief were updated together.
