# Assistant: Company Profile, Work Schedule & Holidays, Attendance Policies

The assistant gains retrieval (RAG) and function calling for the three Company Setup
screens that decide how a day is judged:
- the **company profile**, with the clock attendance is judged on;
- the **holiday calendar and shift templates**;
- the **attendance policies**.

Every change goes through the screen's own path and rules. A change that reaches other
people's days waits for a Confirm, and the confirmation card now says whom it reaches.
See
[ADR 0053](../decisions/0053-assistant-company-profile-schedules-and-attendance-policies.md).

## Highlights

- **Read from the records:** "when is the next holiday?", "what time zone are we
  on?" and "what are our attendance rules?" are answered before the model is called.
- **"If someone clocks in at 8:20 and leaves at 7pm, what happens?"** runs the policy
  editor's worked example: the real evaluator, writing nothing.
- **"Which rules is Maria judged by?"** names the policy and why: her schedule's, her
  department's, the company default, or the built-in rules.
- **A confirmation card says whom a change reaches.** For example, "It judges 42 people
  today; their days from now on follow the change, and days already recorded keep
  their rules." Or, for a time zone, the time there and here.
- **What stays on the screens:**
  - statutory numbers and the join code, which are never read;
  - the logo;
  - capture controls (geofence, office networks, selfies, sources) and punch windows;
  - rotations and split shifts;
  - permanent deletion.

## Backend

- **`CompanyProfileModule`:**
  - reads: `get_company_profile`;
  - writes: `update_company_profile`;
  - confirmed: `set_company_timezone`;
  - retrieval: a profile topic.
- **`SchedulesModule`:**
  - reads: `find_holidays`, `find_work_schedules`, `get_work_schedule`;
  - writes: `add_holiday`, `update_holiday`, `restore_holiday`,
    `create_work_schedule`, `restore_work_schedule`;
  - confirmed: `update_work_schedule`, `set_default_schedule`,
    `archive_work_schedule`, `archive_holiday`;
  - retrieval: the next holidays and the schedules.
- **`AttendancePoliciesModule`:**
  - reads: `find_attendance_policies`, `get_attendance_policy`,
    `get_worked_example`, and `get_applicable_policy` (which also needs
    `employees.view`);
  - writes: `create_attendance_policy`, `restore_attendance_policy`;
  - confirmed: `update_attendance_policy`, `set_default_attendance_policy`,
    `archive_attendance_policy`;
  - retrieval: the policies' leading rules and how one is chosen.
- **`Contracts\ExplainsConsequences`:** a module's one-sentence reach for a held call,
  shown on the confirmation card. `Assistant::hold()` cleans it and puts it before the
  reason.
- **`Support\Attendance\AttendanceCoverage`** counts whom a schedule or policy reaches
  today by asking `ShiftResolver` and `PolicyResolver`, not by counting links.
- **`Support\Attendance\PolicyDescription`** is the server's copy of the policy
  editor's group summaries, so chat and screen describe a policy alike.
- **Shared workflows:**
  - `HolidayWorkflow`, `WorkScheduleWorkflow` (`WorkScheduleException`) and
    `AttendancePolicyWorkflow` (`AttendancePolicyException`);
  - `CompanyProfileWriter::save()`;
  - `HolidayController`, `WorkScheduleController`, `AttendancePolicyController` and
    `CompanyProfileController` are thin callers of them, with the same toasts.
- **Requests usable without a route:** `WorkScheduleRequest::validatePattern()` and
  `AttendancePolicyRequest::rulesFor()`.
- **`Module::invalid()`** takes a request's attribute names and cross-field checks.
- **Imperatives** gain declare, turn, enable, disable and require.

## Fixes on the screens

- **Restoring a policy** whose name was reused while it was archived is refused. There
  is no unique index, so it used to leave two live policies of one name, and the editor
  refused to save either.
- **A time-zone change** is now named in the profile's audit line (old → new).
- **A schedule and its day pattern** are written in one transaction.

## Frontend

- **The chat button** also appears for `setup.company.view`, `setup.schedule.view` and
  `setup.attendance-policies.view`.
- **New suggestions:** "When is the next holiday?" and "How do our attendance rules
  handle overtime?".

## Verification

- **Pest:** the full suite passes (1307 tests: the previous 1268 plus 39 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database, light and dark:
  - an `update_attendance_policy` was held through the real assistant. Its card
    read "It judges 120 people today; …" and waited for Confirm;
  - confirming applied it, toasted, reloaded the policy row, and replied with the
    change itself ("Lateness: 10m grace a day … → 15m grace a day …");
  - it was audited "via assistant";
  - no console errors.

## Tests

- **`Setup/CompanyProfileAssistantTest` (9):**
  - permissions and the confirm;
  - no statutory number, join code or logo read or declared;
  - the profile brief;
  - edits by the screen's rules;
  - zones by name or city, never an offset;
  - the reach line;
  - the screen's time-zone audit;
  - tenant isolation.
- **`Setup/SchedulesAssistantTest` (13):**
  - permissions, confirms and the run-time refusal;
  - yearly holidays across a range, and the holiday brief;
  - adding once, and a past-date note;
  - two holidays of one name told apart by date;
  - the day-by-day read-out;
  - creating by the screen's rules (core hours, policy, duplicate names);
  - edits that never flatten a split shift or rotation;
  - the default, and the reach line;
  - archive and restore;
  - tenant isolation.
- **`Setup/AttendancePoliciesAssistantTest` (13):**
  - permissions and confirms;
  - no existence oracle without the directory;
  - no capture controls offered or addresses read;
  - the group-by-group explanation;
  - the worked example, writing nothing;
  - which policy judges whom;
  - the brief;
  - presets adjusted by the screen's rules and messages;
  - an edit's before → after, and off-by-zero;
  - the reach line;
  - the default;
  - the restore name clash, in chat and on the screen;
  - tenant isolation.
- **`Setup/ScheduleSetupTest` (3):** the holiday and schedule screens through the
  workflows, including the assigned-schedule delete guard.
- **`Assistant/AssistantGuardTest` (+1):** a held call's card carries its reach before
  anybody confirms.

## Notes

- **Assistant coverage.** It now covers:
  - Employees, Leave, Attendance, Recruitment, Onboarding, Offboarding;
  - Performance, Training, Awards, Events;
  - Departments, the Company Profile, Work Schedule & Holidays, Attendance Policies;
  - the Dashboard and Reports.

  Still screen-only:
  - locations, devices, leave types, KPI, award types and templates;
  - user and role management;
  - the analytics pages.
