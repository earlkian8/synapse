# Attendance Policies

**How a day is judged.** Grace, rounding, lateness and short-day thresholds, overtime,
breaks and night work — chosen from a preset and adjusted through typed options, never
written as a formula. Every attendance day is judged by one policy, frozen onto the day
when it opens, and comes out as minute buckets a payroll system can read. The *why* is
[ADR 0038](../decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md);
this is the *how*. Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/setup/attendance-policies`
> Sidebar: Company Setup → Attendance Policies (gated by `setup.attendance-policies.view`)
> Also offered as step 4 of the [setup wizard](./company-setup-wizard.md).

## Surfaces

- **Company Setup → Attendance Policies** — a card list like the other catalogues: each
  row names the policy, marks the **company default** (the star toggles it) and the
  preset it came from, leads with its distinctive rules ("After 8h a day, needs approval ·
  1h unpaid after 5h if none is punched · Night 22:00–06:00") and says where it is named
  (schedules, departments, assignments). Archive, restore, and permanent delete — refused
  while anything still names the policy.
- **The editor** (a centred modal) — a new policy starts from a preset chip or from the
  built-in rules; one made from a preset can be **reset** to it. The settings sit in
  collapsible groups whose header always says what the group amounts to, so a closed group
  still reads. Beside them, the **worked example**: a sample day (working, rest day or
  holiday; the shift, the clock-in and clock-out, an optional break, the schedule's grace)
  drawn on a strip — the shift, the time on the clock, the break, the night window and
  what ran past the shift — above a ledger of what the day comes out as: status and flags,
  regular, overtime (and whether it awaits approval), late (and how much grace forgave),
  short, break (and whether it was deducted), and night, rest-day and holiday minutes.
  The verdict is the server's own evaluator (`POST …/preview`, writing nothing), asked a
  moment after typing stops.
- **Where a policy is named:** the schedule editor ("Attendance policy"), the department
  form, and both assign dialogs — the roster's and the employee profile's ("Judged by",
  only when someone is judged differently from their shift). The profile's schedule
  history shows a policy that singles somebody out.

## Which policy applies

`App\Support\Attendance\PolicyResolver` — most specific first:

**assignment → schedule → department → company default → built-in fallback**

1. the policy on the **dated assignment** covering the date;
2. the policy on the **schedule** the day's shift came from — including one a roster
   override borrowed for the day;
3. the **department's** policy;
4. the policy marked **company default** (not archived);
5. the **built-in fallback** — a policy with nothing set.

`forMany()` resolves a whole roster over a range in five queries whatever the range, and
in one when the company has no policies at all. `ResolvedPolicy` carries the settings, the
id and name, and the **source** (which link won).

## The built-in rules

The fallback judges a day exactly as attendance did before policies existed — exact punch
times, each schedule's own grace, overtime as whatever was worked beyond the day's
required minutes (never needing approval), breaks exactly as punched, no thresholds, no
night differential. A company that configures nothing sees no change in its numbers.

## Settings

Stored as grouped JSON (`attendance_policies.settings`) and read by
`AttendancePolicySettings`, which gives any missing or malformed key its default. Minutes
are whole minutes; "off" is `null`.

| Group | Key | Default | Meaning |
| --- | --- | --- | --- |
| **Punch windows** | `early_clock_in_minutes` | 240 | How early a clock-in still counts towards the shift. |
| | `max_shift_span_minutes` | 960 | How long an open shift keeps claiming punches — long enough for a double shift, short enough that a forgotten clock-out does not swallow tomorrow. 240–1440. |
| **Lateness** | `enabled` | true | Off: nobody is ever late. |
| | `grace_minutes` | null | Minutes forgiven each day. Null uses each **schedule's** grace. |
| | `grace_mode` | `per_day` | `per_day`, or `monthly_allowance` — one pool for the month. |
| | `monthly_grace_minutes` | 0 | The pool. Lateness is taken from it until it runs out; the day it does is the first that counts. Required (>0) in allowance mode. |
| | `half_day_after_minutes` | off | Later than this is a `half_day`. |
| | `absent_after_minutes` | off | Later than this is `absent` (flag `late_absent`). Must exceed the half-day threshold. |
| **Undertime** | `basis` | `schedule` | `schedule` — short by leaving before the shift ends (flexible: the core window, or the hours, whichever is worse); `hours` — short only by the hours. |
| | `half_day_below_minutes` | off | Fewer worked minutes than this is a `half_day`. |
| | `minimum_minutes_for_present` | off | Fewer is `absent` (flag `below_minimum`). Must be below the half-day line. |
| **Rounding** | `mode` | `none` | `none`, `nearest`, `up`, `down` — on the organisation's clock. |
| | `unit` | 15 | 5, 10, 15 or 30 minutes. |
| | `apply_to` | `both` | `in`, `out` or `both`. Applied to the judged times; the punches themselves never change. |
| **Breaks** | `paid_break_minutes` | 0 | This much of a punched break counts as worked. |
| | `auto_deduct_minutes` | 0 | An unpaid break taken off a closed day on which **no** break was punched… |
| | `auto_deduct_after_worked_minutes` | 0 | …once it has run at least this long (flag `break_deducted`). |
| | `max_break_minutes` | off | A longer break is flagged `break_exceeded`, and the excess is owed like leaving early. |
| **Overtime** | `basis` | `daily` | `none`, `daily`, `weekly`, `daily_and_weekly`. |
| | `daily_after_minutes` | null | Overtime beyond this much in a day. Null is the day's required minutes. |
| | `weekly_after_minutes` | 2400 | Overtime once the Mon–Sun week's **regular** minutes pass this — so under `daily_and_weekly` a minute already paid as daily overtime is never counted again. |
| | `min_block_minutes` | 0 | Less than this in a day is not overtime at all. |
| | `count_early_clock_in` | true | Off: on a fixed shift, minutes before the start are not worked (so never overtime). |
| | `requires_approval` | false | On: overtime is computed but not approved (flag `unapproved_overtime`) until Phase 3's approval flow signs it off. |
| | `rest_day_all_overtime` | false | Every minute worked on a rest day is overtime. |
| | `holiday_all_overtime` | false | Every minute worked on a regular or special non-working holiday is overtime. |
| **Missing clock-out** | `action` | `flag` | `flag`, `auto_close_at_shift_end`, `auto_close_after_minutes`. **Stored now; applied by the end-of-day job (Phase 4).** |
| | `after_minutes` | 120 | For `auto_close_after_minutes`. |
| **Night differential** | `enabled` | false | Minutes worked inside the window are bucketed. |
| | `start` / `end` | 22:00 / 06:00 | On the organisation's clock; an end at or before the start crosses midnight. |
| **Capture** | `allowed_sources` | all five | `web`, `mobile`, `kiosk`, `biometric`, `manual` — at least one. **Stored now; enforced in Phase 4**, like the rest of this group. |
| | `selfie_required` | false | |
| | `geofence` | `off` | `off`, `flag`, `block`. |
| | `web_ip_allowlist` | [] | IP addresses or CIDR ranges (up to 50). Empty allows any network. |

Validation lives in `AttendancePolicyRequest` — `settingsRules()` for each field and
`validateSettings()` for the ones that must agree — shared by the editor, the wizard and
the worked example.

## Presets

`App\Support\Attendance\AttendancePolicyPresets` — overrides over the defaults above.

| Key | Name | What it sets |
| --- | --- | --- |
| `ph_labor_code` | Philippines — Labor Code | Overtime after 8h a day, requiring approval; a 1h unpaid lunch deducted after 5h if none is punched; night differential 22:00–06:00. Rest-day and holiday minutes are bucketed (as under every policy). |
| `standard_40h_week` | Standard 40-hour week | Weekly overtime after 40h; a 30-minute unpaid break after 6h. |
| `flexible_no_lateness` | Flexible, no lateness | Lateness not judged; short only on hours. |
| `shift_work` | Shift work | Nearest 15 minutes, in and out; overtime after 8h a day and 40h a week; a forgotten clock-out closed 2h after the shift. |

## How a day is evaluated

`AttendanceCalculator::evaluate(punches, DayRules, DayContext): DayResult` is pure — no
database, no clock. `DayContext` is gathered by `AttendanceClock::contextFor()`: the shift
as instants, the organisation's zone, approved leave, and — **only when the policy needs
them** — the week's regular minutes before the day and the lateness the month has already
forgiven. In order:

1. **Round** clock-ins and clock-outs on the local clock (never earlier than the event
   before them).
2. **Pair** punches into work and break intervals; a stretch still open counts nothing yet.
3. **Clip** work before a fixed shift's start when early clock-ins do not count.
4. **Breaks** — pay the paid part of a punched break; deduct an unpunched unpaid one;
   note one that ran over.
5. **Late and undertime** by the schedule's type, after grace (per day or from the
   monthly allowance). An over-long break's excess is added to undertime.
6. **Buckets** — overtime by basis and minimum block; regular = worked − overtime;
   approved overtime; night; rest day; holiday.
7. **Thresholds** — on a working day only: absent (too late, too short), then half day.
8. **Status and flags.** An open day is `incomplete`; otherwise late, then undertime, then
   present.

`DayResult::applyTo()` writes the verdict onto a record; `first_in_at` / `last_out_at`
stay the raw punches.

## Snapshots and re-applying

The day's `rules` snapshot (`DayRules`, `version: 3`) carries the policy's id, name,
source and complete settings. Editing or archiving a policy never changes a day already
recorded. **Re-apply rules** — on the day modal, or over the period on screen — re-resolves
both the shift and the policy, and is activity-logged. Because weekly overtime and a
monthly allowance read the days before each one, re-applying and
`attendance:recompute` walk days in date order; correcting an earlier day does not
re-judge later ones until they are re-applied.

## Payroll period summary

`GET /attendance/export?tab=period` — the anchor `date`'s month, or `from` and `to`
(`Y-m-d`, at most 62 days); honours `department` and `search`. Offered on the monthly tab
as **Payroll summary**. One row per employee; figures in **whole minutes**, never money
(ADR 0019). The same file is written when an attendance period locks, and kept as that
period's **payroll file** (ADR 0039). The columns, in order
(`PeriodSummaryExport::COLUMNS`):

| Column | Meaning |
| --- | --- |
| Employee | Full name. |
| Employee No. | |
| Department | |
| Period Start / Period End | The range covered. |
| Days Worked | Days `present`, `late`, `undertime`, `half_day` or `incomplete`. |
| Absences | Days `absent` — including a punched day judged absent by a threshold. |
| Half Days | Days `half_day`. |
| Late Days | Days whose status is `late`. |
| Holidays | Non-working holidays nobody was expected to work. |
| Worked (min) | `worked_minutes`. |
| Late (min) | `late_minutes` — lateness grace did not forgive. |
| Undertime (min) | `undertime_minutes`. |
| Regular (min) | `regular_minutes`. |
| Overtime (min) | `overtime_minutes` — computed. |
| Approved Overtime (min) | `approved_overtime_minutes`. |
| Night (min) | `night_minutes` — a tag over worked minutes. |
| Rest Day (min) | `rest_day_minutes` — a tag. |
| Holiday (min) | `holiday_minutes` — a tag. |

`Regular + Overtime = Worked`. Night, rest-day and holiday minutes overlap them: a payroll
system applies each premium to the minutes it tags. Days not yet reached count nothing.

## Permissions

`setup.attendance-policies.view` (the screen and the worked example) and
`setup.attendance-policies.manage` (create, edit, default, archive, restore, delete, and
the wizard step). HR Manager holds both. Naming a policy on a schedule, a department or an
assignment goes with that screen's own permission.

## Assistant

No new tools. The retrieved brief about a person reports overtime and how much of it
awaits approval, half days, night, rest-day and holiday minutes, and breaks that ran over.
Approval tools arrive with Phase 3.
