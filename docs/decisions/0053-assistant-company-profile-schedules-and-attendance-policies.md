# 0053 — The assistant sets the company's clock, calendar and attendance rules, and says whom a change reaches

- **Status:** Accepted
- **Date:** 2026-09-28
- **Extends:**
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md)
    (a held call's card gains the reach of the change);
  - [0052 — The assistant shapes the org structure, but not pay, attendance rules or permanent deletes](./0052-assistant-departments-without-pay.md)
    (answers its objection to confirming attendance settings in chat).
- **Related:** [Company Profile](../modules/company-profile.md#the-assistant),
  [Work Schedule & Holidays](../modules/work-schedule-holidays.md#the-assistant),
  [Attendance Policies](../modules/attendance-policies.md#assistant).

## Context

Three Company Setup screens decide how everyone's day is judged:

- the **Company Profile** holds the time zone every attendance day is judged on
  (ADR 0036), beside the company's names, contact details, statutory employer
  numbers, logo and join code;
- **Work Schedule & Holidays** holds the shift templates people are assigned to
  (ADR 0037) and the holiday calendar;
- **Attendance Policies** holds how a day is judged: grace, thresholds, rounding,
  breaks, overtime, night differential, and the capture controls (ADR 0038, 0040).

ADR 0052 kept a department's schedule and policy off the assistant. Its reason: a
confirmation card shows *what* changes, "grace minutes: 10", not what it does to the
days of the hundreds of people it applies to. That objection applies to all three
screens here, and it had to be answered before any of them could be offered.

Some of what these screens hold is not settings at all:

- statutory numbers are what payroll remits against;
- the join code is a credential (ADR 0026);
- capture is a set of controls against punching from the wrong place, device or
  network.

## Decision

1. **A held call can say whom it reaches.** A module may implement
   `Contracts\ExplainsConsequences`. When a call is held, its one sentence is computed
   from the records and shown on the confirmation card, above the reason it is held.
   For example: "It judges 42 people today; their days from now on follow the change,
   and days already recorded keep their rules." The sentence is read-only and cleaned
   as untrusted text, and it never decides anything: the call is re-checked and
   re-validated when it runs.
2. **Reach is resolved, not counted.** `Support\Attendance\AttendanceCoverage` asks
   the punch engine's own resolvers who works a schedule, or is judged by a policy,
   today. A policy named on a department reaches nobody there whose assignment or
   schedule names another one, so counting links would be wrong.
3. **Company Profile:**
   - the assistant reads the profile and edits the names and contact details;
   - it changes the time zone, behind a Confirm, as a region/city zone and never an
     offset;
   - statutory numbers are said to be *on file* or *missing*, never read or written;
   - the join code is never read, rotated or toggled;
   - the logo stays on the screen.
4. **Work Schedule & Holidays:**
   - **the calendar:** the assistant lists, adds, edits, archives and restores
     holidays. Yearly holidays are expanded onto each year asked about, and a date
     must be `YYYY-MM-DD`;
   - **schedules:** it reads each one day by day with who works it, and creates or
     reshapes a weekly schedule with one set of hours;
   - **what stays on the screen:** rotations, split shifts, different hours on
     different days and start/end limits. A schedule that has any of them is refused,
     never flattened;
   - **waits for Confirm:** editing a schedule, making one the default, archiving
     one, and archiving a holiday.
5. **Attendance Policies:**
   - **reads:**
     - the assistant explains a policy, a preset or the built-in rules in the
       editor's own words (`PolicyDescription`);
     - it runs the editor's worked example (`WorkedExample`, the real evaluator,
       writing nothing);
     - it says which policy judges a person and why, which also needs
       `employees.view` and is checked before the name is looked up;
   - **writes:**
     - it creates a policy from a preset, adjusting the typed options;
     - changing a policy, making one the default and archiving one wait for Confirm;
   - **stays on the screen:** capture, punch windows and permanent deletion. Reads
     summarise capture without the network addresses.
6. **One path for each screen:**
   - `HolidayWorkflow`, `WorkScheduleWorkflow` and `AttendancePolicyWorkflow` hold
     every write, and `CompanyProfileWriter::save()` holds the profile's;
   - the controllers are thin callers of them, and so is the assistant;
   - each form's rules are reachable without a route:
     `WorkScheduleRequest::validatePattern()` and
     `AttendancePolicyRequest::rulesFor()`;
   - `Module::invalid()` takes a request's attribute names and its cross-field
     checks.

## Consequences

- **"When is the next holiday?", "what are the Night Shift's hours?", "how do we handle
  overtime?" and "if someone clocks in at 8:20, what happens?"** are answered from the
  records, the first three before the model is called.
- **A confirmation now carries its reach.** "Set our time zone to New York" shows the
  time there and here, and what moves. "Require approval for overtime at Head office"
  shows how many people Head office judges today. ADR 0052's department link could use
  the same line; it stays on the screen for now.
- **The screens changed in small ways:**
  - **Time-zone audit:** a changed time zone is written into the profile's audit line,
    old → new.
  - **Restore name clash:** restoring a policy whose name was reused while it was
    archived is refused. There is no unique index, so it used to leave two live
    policies of one name, neither of which the editor would save.
  - **Atomic schedule writes:** a schedule and its pattern are now written in one
    transaction.
- **The assistant is stricter than the screens on names.** It refuses:
  - a work schedule name another schedule already has, which the screen allows;
  - a policy name that differs from another only in case. The screen's rule compares
    exactly, so "office" could sit beside "Office", and neither could then be named
    in chat.
- **Still screen-only:**
  - the other Company Setup screens (locations, devices, leave types, KPI, award types,
    templates);
  - user and role management;
  - the analytics pages.

## Alternatives considered

- **Keeping attendance settings screen-only, as ADR 0052 did for departments.** The
  objection was that a card cannot show a change's reach. With the reach on the card,
  a plain instruction backed by the worked example gives the person asking better
  grounds to decide than the old card did.
- **Counting the schedules, departments and assignments that name a policy.** This is
  cheaper, but it answers "where is it named", not "whose days does it judge". Those
  differ whenever a more specific link wins, which is the ordinary case.
- **Letting the assistant loosen capture behind a Confirm.** Capture controls exist to
  stop the wrong punch. A chat that reads text other people wrote is the last place a
  geofence or an office network should be switched off, confirmed or not.
- **Offering the full day pattern as a nested argument.** Rotations and split shifts
  need 7–84 rows. A sentence does not describe them unambiguously, and a mistake would
  silently re-time whole rosters. The editor's 14-day preview is where they are checked.
