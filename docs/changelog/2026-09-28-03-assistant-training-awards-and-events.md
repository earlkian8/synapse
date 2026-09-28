# Assistant: Training, Awards and Events

The assistant gains retrieval (RAG) and function calling for the last three
workforce modules: **Training & Development**, **Awards & Recognition** and
**Events & Meetings**. They are built on ADR 0049's defences, and they tighten
those defences for every module:

- anything that notifies people waits for a Confirm;
- record names in the instruction are cleaned.

Building one shared path for the screens and the assistant also turned up, and
fixed, defects on the screens: event times read as UTC, invitations and the
calendar download throwing errors, and cross-workspace ids passing validation. See
[ADR 0050](../decisions/0050-assistant-training-awards-and-events.md).

## Highlights

- **"How did the Excel course go?", "what has Maria completed?", "enroll the new
  hires in Safety 101"** are answered and done from the chat. Capacity and
  eligibility work as on the screen.
- **"Who should get Employee of the Month?"** reads the nomination board's own
  ranking, breakdown and repeat-winner flag included. HR can give, revise or take
  back an award.
- **"Any meetings this week?", "who hasn't replied to the town hall?"** are read from
  the calendar. HR can schedule and edit events, record replies, invite people or
  whole departments, and send reminders.
- **Nothing that notifies people happens without a Confirm.** Invitations and
  reminders always stop at the confirmation card, however plainly they were asked for.
- **A time means the office's clock.** "Friday 2pm" in chat, and 2pm in the event
  form, are 2pm in the office. Before, the form stored it as 2pm UTC.

## Backend

### New modules

- **`TrainingModule`:**
  - reads: `find_training_programs`, `get_training_program`,
    `find_training_enrollments`, `training_summary`;
  - writes: `create_training_program`, `update_training_program`,
    `enroll_in_training` (up to 25 names, all-or-nothing),
    `update_training_enrollment`, and `remove_from_training` and
    `archive_training_program`, which always need confirmation;
  - retrieval: a person's enrollments, and a training topic.
- **`AwardsModule`:**
  - reads: `find_awards`, `list_award_types`, `awards_summary`, and
    `get_award_nominees` (`awards.manage`, like the board);
  - writes: `give_award`, `update_award`, and `remove_award`, which always needs
    confirmation;
  - retrieval: a person's recognitions (their own need no permission, as on mobile),
    and a recognition topic with front-runners for `awards.manage`.
- **`EventsModule`:**
  - reads: `find_events` (what is ahead by default; title, kind, status, window,
    invitee), `get_event` (who accepted, declined or has not replied, by name);
  - writes: `schedule_event`, `update_event`, `set_event_response`, and
    `invite_to_event` (names and/or departments, capped at 200),
    `remind_event_invitees`, `remove_event_attendee` and `archive_event`, which always
    need confirmation;
  - retrieval: a person's upcoming invitations, and a calendar topic for the next 30
    days;
  - events are identified by title, plus their date when several share it.

### Shared workflows

`Support\Training\TrainingWorkflow` (with `EnrollmentOutcome`),
`Support\Awards\AwardWorkflow` (with `AwardException`) and
`Support\Events\EventWorkflow` (with `EventException`) hold every write. The six
controllers that wrote directly are now thin callers, and the toasts read exactly as
before. `TrainingProgram::analytics()` replaces the controller-private analytics and
is shared by the program screen, the AI read and the assistant. `AwardNominator::for()`
scores a single award type.

### `Module` base

- `resolveEmployee()`, shared by every module (Performance's private copy is gone).
  It takes an employee number exactly, and among several name matches, the exact full
  name; otherwise nobody.
- `resolveEmployees()` — a list resolved whole or not at all.
- `catalog()` — cleaned guidance lists (below).
- `isoDate()` and `invalid()` — values checked against the screen's own FormRequest
  rules.

### Security

- **Notifying is consequential.** Invites and reminders are `confirmTools()`, and the
  instruction lists them among the significant actions.
- **Guidance catalogs are cleaned.** Record names used to go raw into the system
  instruction, outside the data fence: departments, positions, schedules, leave types,
  onboarding programs, pipeline stages, cycles, programs and award types. Every list
  now goes through `Module::catalog()` and `UntrustedText`, and the security block
  says those names are data.
- **Insight prompts:**
  - `TrainingInsights` and `AwardCitationWriter` now carry the security block and
    clean every digest field;
  - a drafted citation comes back as one plain paragraph;
  - `PerformanceInsights` only capped the length of the remarks in its digest, so
    line breaks survived and could forge a heading. The digest is now cleaned.
- **Tenant-scoped validation.** Five FormRequests now use `TenantRule::exists`:
  `EmployeeAwardRequest`, `AwardCitationRequest`, `EnrollEmployeesRequest`,
  `BulkEnrollmentRequest` and `EventAttendeeRequest`. An award could previously be
  stored against another organisation's employee or award type.
- **Imperatives** gain enroll, invite, give, award, recognise, grade, drop and
  reschedule. "Enroll Maria in Excel?" is an instruction, not a question.

### Fixed

- **Event times were read as UTC.** The form's `datetime-local` has no zone. A Manila
  user who entered 2pm saw 10pm, and every save of the edit form moved the event
  another eight hours. `EventWorkflow` now reads the time on the organisation's clock
  (`OrganizationClock::parse()`), and the invitation text, the dashboard brief and the
  chat show it on that clock.
- **Inviting anyone with a login, and every reminder, threw an error.** Dates are
  immutable app-wide (`Date::use(CarbonImmutable)`), and `notify()` declared a
  mutable `Carbon` return.
- **The `.ics` download threw an error** for the same reason. A bare CR in a title
  could also start a property of its own; it is now escaped.
- **An award dated "today" could be refused as in the future** on a Manila morning,
  because the rule compared against UTC's today. It now uses the organisation's.
- **A retired award type could be given anew** through the form's request. The
  workflow refuses it; an award that already has it can still be edited.

### Changed

- **Dashboard's `list_upcoming_events` is gone.** Events owns `find_events`, and one
  tool per job saves tokens on every turn.

## Frontend

- **New `award` card kind**, with a trophy icon.
- **Empty-state suggestions follow permissions.** Nine starting points, including
  trainings, meetings and Employee of the Month, are shown only to someone who could
  act on them, six at most. The intro copy names the modules the assistant now covers.
- **The chat button** also appears for `training.view` and `awards.view`.
- **Opening a stored conversation no longer replays it.** Every action in a
  conversation opened from history used to toast again and reload the page. History
  messages are now marked `replayed`, and only actions from this session announce
  themselves.

## Verification

- **Pest:** the full suite passes (1222 tests: the previous 1168, plus 55 new, minus
  the dashboard events test that moved to Events).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database, with a real award
  and a real held invite planted through the assistant and a scripted model:
  - the permission-filtered suggestions and the new intro copy show in light and dark;
  - the award card renders with its trophy;
  - opening the conversation from history replays no toast;
  - Confirm on the held invite created the invitation (audited "via assistant") and
    toasted once;
  - no console errors.

## Tests

- **`Training/TrainingAssistantTest` (16 tests):**
  - permissions and run-time refusal, and which tools need confirmation;
  - the program read-out (seats, outcomes, at-risk and dropped names), the derived
    status filter, ambiguous names, tenant isolation;
  - capacity and eligibility on enroll, all-or-nothing lists, and the exact-name rule;
  - grading against the form's rules, with the completion stamp;
  - create/update validation, including a backwards window, and removing capacity;
  - remove and archive;
  - the person brief and its gating, and the training topic.
- **`Training/TrainingTest` (4 tests):** the screens' create, edit and archive; enroll
  toasts; a cross-workspace enroll refused; completion stamps singly and in bulk.
- **`Awards/AwardsAssistantTest` (9 tests):**
  - permissions and confirmation;
  - giving an award (granter, the organisation's today, the audit line);
  - retired types and future dates refused;
  - locating an award by type or date, and switching to a retired type refused;
  - tenant isolation;
  - the nominee ranking;
  - own-record retrieval, and the topic with and without front-runners.
- **`Awards/AwardsTest` (3 tests):** give, edit and remove; a retired type refused
  but still editable; a cross-workspace employee or type refused.
- **`Events/EventsAssistantTest` (16 tests):**
  - permissions and confirmation;
  - the wall clock;
  - a past start, a backwards window and unreadable times refused;
  - end-only moves checked against the start;
  - recurring titles told apart by date;
  - invites by name and department, with skips and notifications;
  - all-or-nothing lists, and tenant isolation;
  - responses and reminders;
  - a plain-instruction invite held, then confirming it sending exactly once;
  - the read-out, the calendar window, the person brief, and an injected title
    flattened in the topic.
- **`Events/EventsTest` (5 tests):**
  - the form's wall clock, and no drift on save;
  - invite notifications, skips and reminders;
  - a cross-workspace invite refused;
  - no reminder once an event is over;
  - the `.ics` download, and CR escaping.
- **`Assistant/AssistantGuardTest` (+2 tests):**
  - a catalog name cannot forge a rule in the instruction;
  - the training and citation prompts treat their digests as data, and a citation
    comes back as one plain paragraph.
- **`Dashboard/DashboardAssistantTest`:** the events-window test moved to Events, and
  the tool list no longer includes `list_upcoming_events`.

## Notes

- **Events saved before this change keep their stored instant.** Anything entered in
  the form from a non-UTC browser was stored shifted, and still shows at the time it
  was mistakenly given. Re-save it with the correct time to fix it.
- **Still not changed (outside these modules):**
  - Onboarding, Offboarding and Recruitment requests still use unscoped
    `Rule::exists`. Their controllers resolve the ids through the tenant-scoped
    models, so nothing crosses workspaces, but pass/fail is still an existence oracle.
  - The web leave route lets `leave.request` holders file for any `employee_id`. This
    was noted with ADR 0049.
