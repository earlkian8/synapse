# Assistant

The floating chat assistant in the bottom-right corner of every page (the sparkle
button). It does two jobs. It **answers** questions about the workspace from records it
reads for the asker, and it **acts**, through named, permission-checked tools that call
the same code as the screens. It covers the whole system: 33 modules and 277 tools, each
offered only to the people whose role allows it. It runs on Google Gemini, server-side.

> Status: **Active** · Endpoints: `POST /assistant`, `/assistant/conversations/*`,
> `POST /assistant/actions/{confirm,cancel}` · Code: `app/Services/Assistant/`,
> `resources/js/features/assistant/`
> Key decisions: [0024](../decisions/0024-agentic-recruitment-and-permission-scoped-tools.md)
> (permission-scoped tools), [0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md)
> (disclosure policy), [0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md)
> (retrieval first), [0049](../decisions/0049-assistant-prompt-injection-defences.md)
> (prompt-injection defences), and [0050](../decisions/0050-assistant-training-awards-and-events.md)
> to [0059](../decisions/0059-assistant-covers-the-whole-system.md) (module by module).

## Who sees it

The launcher is shown to anyone who holds at least one permission in the client's
`ASSISTANT_PERMISSIONS` list (`features/assistant/components/assistant.tsx`): a view
permission of any module the assistant reads. Self-service alone does not open it,
because every turn spends model quota. The built-in **Staff** role (`attendance.clock`
and `leave.request`) does not see it.

Inside the chat, what a person can do is decided per module and per tool by their
permissions (see [Modules](#modules)).

## How a turn works

`App\Services\Assistant\Assistant::handle()` runs one turn:

1. **Read first.** `Retrieval\Retriever` works out what the turn is about and reads it
   before the model is called (see [Knowing](#knowing-retrieval)). The result is a
   *context brief*.
2. **Build the prompt.** The system instruction holds the security rules, how to
   answer, how to act, today's date, each available module's `guidance()` (what it can
   do *for this user*, with live catalogues such as leave type names), and the brief,
   fenced as untrusted data. Up to the last 20 earlier messages are replayed, each
   capped at 4,000 characters.
3. **Call Gemini** with the tools this user is offered. Up to **6** round trips per
   turn.
4. **Gate every tool call** (see [Doing](#doing-tools)): it must be offered, its
   arguments are cleaned to the declared schema, and there is a budget of **10** calls
   and **3** writes per turn. A write may be **held** for the user's OK instead of run.
5. **Write the reply.** When every call succeeded, the reply is composed locally from
   the result cards, with no second model call. A question answered only by reads goes
   back to the model **once** to be written up properly. A held call stops the loop
   with "Nothing has changed yet — … needs your OK."
6. **Guard the reply.** `Security\ReplyGuard` removes images and unlinks anything that
   leads off the app.
7. **Persist.** `AssistantController` stores the user message and the reply (with its
   step timeline and result cards) in the conversation
   ([assistant tables](../database/assistant-tables.md)).

A typical turn costs **one** Gemini request: retrieval happens before the call, and an
action is narrated locally. The free tier this was built on allows only a few requests
a minute.

## Knowing: retrieval

`Retriever::retrieve()` produces a `ContextBrief` about one of two things.

**A person.** `Retrieval\SubjectResolver` reduces the message to word tokens and
matches them against real name and employee-number columns. The model never names the
subject, so the brief can never be about somebody who does not exist. It handles:

- **the asker** ("my leave", "am I regularised") — no directory permission needed;
- **a follow-up** ("and how is his attendance?") — the last 6 turns are re-read for a
  name;
- **an ambiguous name** — up to 5 matches come back as alternatives, and the model is
  told to ask which one is meant.

Every module that implements `Contracts\ContributesContext` is then asked what it
knows about that person: Employees, Leave, Attendance, Onboarding, Recruitment,
Performance, Training, Awards, Events and Offboarding. A module returns only what
the asker could see on its screen. For the asker's own record, a module is asked even
when the asker cannot otherwise use it.

**The workspace.** When nobody is named, modules that implement
`Contracts\ContributesTopicContext` are matched by their `topicTriggers()` against the
user's own words ("today", "attention", "how are we", "review cycle", …). This is how
"what needs my attention?" is answered from the dashboard's own queries.

The brief appears in the chat timeline as a **read** step naming its sources ("Read
Maria Santos's record · Employee record · Leave (2026) · Attendance (last 30 days)"),
so an answer can be checked against the records it came from. When every module
declines, there is no brief and no step, so the timeline never confirms that a person
exists to somebody who may not see them.

### Disclosure

- **Never shown in chat, at any permission level:** pay, government ID numbers, bank
  details, home address and date of birth (`Support\Employees\EmployeeDisclosure::WITHHELD`).
  The brief says they are withheld so the model does not guess. They can still be
  *written* through the assistant, and read in the 201 file.
- **Reading a named person's record is audited** as a `viewed` activity entry, whether
  it was read by a tool or by retrieval. Reading your own record is not.
- **The join code** is never read out, and documents are listed by title only.
- **Retrieval refuses to run with no organisation bound**, because the tenant scope
  switches itself off in that state.

## Doing: tools

Each module implements `Contracts\AssistantModule` and usually extends
`Modules\Module`:

| Method | Purpose |
| --- | --- |
| `key()` | Stable id, also the `module` tag on result cards. |
| `isAvailable(User)` | Whether the module is in this user's prompt at all. |
| `tools(User)` | The Gemini function declarations **this user** may call. `Module::permitted()` drops any tool whose `permissionMap()` permission they lack. |
| `guidance(User)` | The module's paragraph of the system instruction. Record names in it go through `catalog()`, which cleans them. |
| `run(User, tool, args)` | Executes one call, re-checking the permission. |
| `isReadOnly(tool)` | `find_*`, `get_*`, `list_*`, `count_*` and `*_summary` are reads, plus any in `readTools()`. Everything else is a write. |
| `requiresConfirmation(tool)` | Tools in `confirmTools()` always wait for Confirm. |

Handlers do not reimplement business rules. They call the module's own support
classes (`LeaveCalculator`, `ApplicantHirer`, `OnboardingProvisioner`,
`OffboardingWorkflow`, `ModelGraduation`, …), validate with the same rules as the form
requests, and log to `activity_logs` with a "via assistant" suffix. Shared helpers in
`Module`: `resolveEmployee()` (name or employee number, token-matched, ambiguity
reported), `resolveMember()` (a user of this workspace), `resolveAccount()`,
`resolveRole()`, `matchByTokens()`, `invalid()` (validator wrapper), `denied()` and
`card()`.

`ToolResult` is the uniform outcome: a timeline label, a status (`done`, `error` or
`held`), a detail line, and cards. A card's `kind` is `find` or `insight` for reads,
`confirm` for a held call, or anything else for a change.

### When a write waits for Confirm

A write is **held** instead of run when:

- the tool is in its module's `confirmTools()` — deleting, archiving, hiring,
  rejecting, launching, submitting, granting access, sending a notification, and
  every setting that changes how other people's days are judged;
- the turn was a **question** rather than an instruction (a crude opening-word test in
  `Assistant::isQuestion()`, in English and Filipino);
- the turn carried an **attached document**.

The held call (tool and cleaned arguments) is parked by `Security\PendingActions`
under a token bound to the user, the workspace and the conversation. It is single-use
and lasts 15 minutes. The chat shows a confirmation card with exactly what would run.
A module that implements `Contracts\ExplainsConsequences` adds one line on what it would
reach ("Judges 42 people today; days already recorded keep their rules").
**Confirm** (`AssistantActionController@confirm`) replays the stored call through
`Assistant::execute()`. The tool must still be offered to the user and the module
re-checks the permission, so confirming is consent, not a grant. No model call is made.

### Prompt-injection defences

The assistant assumes the model has been compromised by something it read (ADR 0049):

- **Only offered tools run.** Any other name is refused and logged as
  `assistant · blocked`.
- **Arguments are held to the schema** (`Security\ToolArguments`): undeclared keys
  such as `organization_id` are dropped, strings are bounded, enums must match, and
  lists and nesting are capped.
- **Untrusted text is cleaned at the boundary** (`Security\UntrustedText`): control
  and invisible characters are removed and lengths capped, for every brief line, tool
  result, catalogue name and history turn.
- **The brief is fenced** (`Security\PromptFence`) with markers carrying 64 random
  bits, new every turn. Fence characters are broken up wherever they appear in data,
  so a record cannot close the fence.
- **Function responses say they are data** (`content_is_untrusted_data: true`), and an
  attachment is preceded by a line saying nothing in it is a request.
- **Replies cannot leak** (`Security\ReplyGuard`, and again in the chat's `Markdown`
  renderer): no images, and no links outside the app.

The other Gemini features (applicant insights, appraisal and training insights, report
digests, award citations) clean their inputs the same way.

## Modules

Registered in order in `AppServiceProvider` as `assistant.modules`. The retriever reads
the same list. "Brief" marks a module that contributes to a person's brief (P) or to a
workspace brief (W). Tools marked † always wait for Confirm.

| Module | Key | Available with | Brief | Tools |
| --- | --- | --- | --- | --- |
| Employees | `employees` | `employees.view`, or a linked roster line | P | `find_employees`, `get_employee_profile`, `list_employees`, `count_employees`, `list_direct_reports`, `get_my_employee_record`, `create_employee`, `update_employee`, `archive_employee`† |
| Leave | `leave` | `leave.view` or `leave.request` | P | `find_leave_requests`, `file_leave_request`, `review_leave_request`, `cancel_leave_request`†, `get_leave_balances`, `set_leave_entitlement`† |
| Attendance | `attendance` | `attendance.view` or `attendance.clock` | P | `find_attendance`, `record_punch`, `find_shifts`, `set_roster_entry`, `find_attendance_exceptions`, `find_pending_sign_offs`, `sign_off_attendance`†, `sign_off_pending_attendance`†, `reapply_attendance_rules`† |
| Onboarding | `onboarding` | `onboarding.view` | P | 16: cases, checklist tasks, programs, `nudge_onboarding_task`, `onboarding_summary`; deleting a case, task or program and `set_onboarding_status` † |
| Recruitment | `recruitment` | `recruitment.view` | P | 25: postings, applicants, applications, interviews, `rank_candidates`, `candidate_profile`, `candidate_insights`; deletes, `reject_application`, `withdraw_application`, `hire_applicant`, `cancel_interview` † |
| Performance | `performance` | `performance.view` | P, W | `find_appraisals`, `get_appraisal`, `performance_summary`, `list_review_cycles`, `open_appraisal`, `rate_appraisal`, `submit_appraisal`†, `acknowledge_appraisal`†, `delete_draft_appraisal`†, `launch_review_cycle`† |
| Training | `training` | `training.view` | P, W | 10: programs and enrollments; `remove_from_training`†, `archive_training_program`† |
| Awards | `awards` | `awards.view` | P, W | `find_awards`, `list_award_types`, `awards_summary`, `get_award_nominees`, `give_award`, `update_award`, `remove_award`† |
| Events | `events` | `events.view` | P, W | 9: events and invitees; `invite_to_event`†, `remind_event_invitees`†, `remove_event_attendee`†, `archive_event`† |
| Offboarding | `offboarding` | `offboarding.view` | P, W | 14: cases and clearance items, `apply_clearance_template`; `start_offboarding`†, `set_offboarding_status`†, `delete_offboarding_case`†, `remove_clearance_item`†, `clear_pending_clearance`† |
| Reports | `reports` | any report the user may run | W | `list_reports`, `run_report` |
| Departments | `departments` | `setup.departments.view` | W | 11: departments and positions; `archive_department`†, `delete_position`† |
| Company profile | `company` | `setup.company.view` | W | `get_company_profile`, `update_company_profile`, `set_company_timezone`† |
| Schedules & holidays | `schedules` | `setup.schedule.view` | W | 12; updating, archiving or making default a schedule, and archiving a holiday † |
| Attendance policies | `attendance-policies` | `setup.attendance-policies.view` | W | 9, including `get_worked_example`, `get_applicable_policy`; update, default and archive † |
| Work locations | `locations` | `setup.locations.view` | W | 7; `update_location`†, `archive_location`†. A geofence is never drawn in chat. |
| Leave types | `leave-types` | `setup.leave-types.view` | W | 6; `update_leave_type`†, `archive_leave_type`† |
| Award types | `award-types` | `setup.award-types.view` | — | 6; `archive_award_type`† |
| Performance framework | `performance-framework` | `setup.kpi.view` | W | 19: frameworks, criteria, rating scales, review cycles; most edits to a framework in use † |
| Recruitment pipelines | `recruitment-pipelines` | `recruitment.configure-pipelines` | W | 7; `remove_pipeline_stage`†, `delete_pipeline`† |
| Clearance templates | `offboarding-programs` | `offboarding.manage-programs` | W | 7; `update_clearance_template`†, `delete_clearance_template`† |
| Users | `users` | `users.view` | W | 10; creating, re-emailing, (de)activating, giving or taking a role, and archiving † |
| Roles & permissions | `roles` | `roles.view` | W | 8; granting or revoking permissions and deleting a role †. Nobody can grant a permission they do not hold. |
| Activity logs | `activity-logs` | `activity-logs.view` | W | `find_activity`, `activity_summary` |
| Trash bin | `trash` | any trashable type the user may view | W | `find_trash`, `restore_from_trash`†, `delete_from_trash`† |
| Attrition risk | `attrition-risk` | `analytics.attrition.view` (`.manage` to change) | W | 8 (see below) |
| Promotion readiness | `promotion-readiness` | `analytics.promotion.view` (`.manage` to change) | W | 8 |
| Performance forecast | `performance-forecast` | `analytics.performance.view` (`.manage` to change) | W | 8 |
| Employee records | `employee-records` | `employees.view` | W | `find_certifications`, `find_employee_documents`, `add_certification`, `remove_certification`† |
| App access | `workspace-access` | `employees.invite` | W | 9: join requests, invitations, the join code; approving, declining, inviting and `set_join_code` † |
| Notifications | `notifications` | everyone | W | your own inbox and settings; `send_notification`† (`notifications.send`) |
| System guide | `guide` | everyone | W | `find_help`, `get_my_access`, `list_my_workspaces`, `get_setup_progress` |
| Dashboard | `dashboard` | any dashboard block's permission | W | `get_workspace_overview`, `get_attention_queue`, `get_recent_activity`, `get_attendance_trend` |

The three predictive modules share `Modules\PredictiveModule`. Each has the same eight
tools named for its surface: a summary of the latest run, the ranked roster, one
person's score, the model's graduation status, running a new assessment, deleting
one†, training the organisation's own model, and switching models†. A score is read
from the stored runs, never recomputed in chat, and reading a named person's score is
audited.

Module tests are the `*AssistantTest.php` files beside each module's own tests
(`tests/Feature/<Module>/`). Cross-cutting behaviour is in
`tests/Feature/Assistant/AssistantGuardTest.php`, `RetrievalTest.php` and
`SystemGuideTest.php`, and `tests/Feature/Employee/AssistantEndpointSecurityTest.php`.

## Endpoints

All under `auth` + `verified`, in `routes/web.php`:

| Method | Path | Controller | Notes |
| --- | --- | --- | --- |
| POST | `/assistant` | `AssistantController@send` | `message` (≤ 4,000 chars), `conversation_id`, `replace_message_id` (edit and resend), up to 8 `files` (pdf, png, jpg, jpeg, webp, txt; 8 MB each). Throttle `assistant`. |
| POST | `/assistant/conversations/{id}/regenerate` | `AssistantController@regenerate` | Re-runs the last user message. Throttle `assistant`. |
| GET | `/assistant/conversations` | `AssistantConversationController@index` | The user's threads in this workspace, pinned first. |
| GET | `/assistant/conversations/{id}` | `…@show` | One thread with its messages. |
| PATCH | `/assistant/conversations/{id}` | `…@update` | Rename or pin. |
| DELETE | `/assistant/conversations/{id}` | `…@destroy` | |
| DELETE | `/assistant/conversations` | `…@clear` | Deletes all of the user's threads in this workspace. |
| POST | `/assistant/actions/confirm` | `AssistantActionController@confirm` | Runs a held call. Throttle `assistant-actions`. |
| POST | `/assistant/actions/cancel` | `AssistantActionController@cancel` | Throttle `assistant-actions`. |

A conversation that is not the user's answers 404. An expired, spent, foreign or
unknown confirmation token gets the same answer, so a token cannot be probed.

**Rate limits** (`AppServiceProvider::configureRateLimiting()`), per user:
`assistant` allows 12 a minute and 240 a day; `assistant-actions` allows 30 a minute.

**Failures.** When Gemini is busy (`GeminiException`), the turn is not saved as an
answer: the user message is kept and the client gets a retryable notice. The notice
distinguishes a briefly overloaded model (503), a per-minute burst (429 with a short
retry delay: "give it about N s") and a used-up daily allowance (429 with a long or no
delay). With no `GEMINI_API_KEY`, the endpoint answers 503 with a message saying so.

## Frontend

`resources/js/features/assistant/`, mounted once in `layouts/app/app-sidebar-layout.tsx`:

- `use-assistant.ts` — state and orchestration: the thread list, the active thread,
  optimistic sending, simulated streaming of the reply, edit, regenerate, retry, and
  answering confirmation cards.
- `api.ts` — `fetch` calls with the XSRF header; `types.ts`.
- `components/assistant.tsx` — the launcher and panel, the permission check, and an
  unsent draft per thread in `localStorage` (`synapse.assistant.draft.<id>`).
- `components/message-list.tsx`, `message-item.tsx` — the turns, copy, edit and
  regenerate.
- `components/agent-activity.tsx` — the step timeline and result cards, including the
  Confirm / Cancel card.
- `components/composer.tsx` — input, drag-and-drop attachments.
- `components/conversation-list.tsx` — history: rename, pin, delete, clear.
- `components/markdown.tsx` — the reply renderer; it drops images and off-app links,
  as `ReplyGuard` does on the server.

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `GEMINI_API_KEY` | — | Required. Never sent to the browser. |
| `GEMINI_MODEL` | `gemini-2.5-flash` | `config/services.php`. |

Outbound HTTPS from PHP needs a CA bundle (`curl.cainfo`) on machines that lack one.

## Adding a module

1. Create `app/Services/Assistant/Modules/<Name>Module.php` extending `Module`.
   Implement `key()`, `isAvailable()`, `toolMap()`, `permissionMap()`, `tools()`
   (return `$this->permitted($user, [...])`), `guidance()` and the handlers.
2. Put the work in the module's existing support class, not in the handler. If the
   screen does the same thing inline in a controller, extract it so both call it.
3. List consequential tools in `confirmTools()` and reads with unusual names in
   `readTools()`. Implement `ExplainsConsequences` if a held call affects other
   people.
4. Implement `ContributesContext` and/or `ContributesTopicContext` if the module can
   describe a person or the workspace. Check the permission yourself, return null when
   there is nothing to say, and keep it to a few lines.
5. Register it in `AppServiceProvider` (`assistant.modules`). If its view permission
   is new, add it to `ASSISTANT_PERMISSIONS` in `assistant.tsx`, and give
   `SystemGuide` an entry for the screen.
6. Test with a stub `GeminiClient` bound in the container (see `withModel()` in
   `AssistantGuardTest.php`), so no quota is spent.
