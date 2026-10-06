# Assistant

The chat assistant, opened from the **Assistant** button in the top bar (or ⌘J / Ctrl+J)
as a panel at the right edge of every page. It does two jobs. It **answers** questions about the workspace from records it
reads for the asker, and it **acts**, through named, permission-checked tools that call
the same code as the screens, and keeps going until the request is done: look up, act,
act again (ADR 0068). It covers the whole system: 33 modules and 278 tools, each offered
only to the people whose role allows it, and each request carries only the tools it is
about. It runs on Google Gemini, server-side.

> Status: **Active** · Endpoints: `POST /assistant`, `/assistant/conversations/*`,
> `POST /assistant/actions/{confirm,cancel}` · Code: `app/Services/Assistant/`,
> `resources/js/features/assistant/`
> Key decisions: [0024](../decisions/0024-agentic-recruitment-and-permission-scoped-tools.md)
> (permission-scoped tools), [0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md)
> (disclosure policy), [0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md)
> (retrieval first), [0049](../decisions/0049-assistant-prompt-injection-defences.md)
> (prompt-injection defences), [0050](../decisions/0050-assistant-training-awards-and-events.md)
> to [0059](../decisions/0059-assistant-covers-the-whole-system.md) (module by module), and
> [0067](../decisions/0067-the-assistant-is-a-panel-opened-from-the-top-bar.md) (the panel
> and the work trace), and [0068](../decisions/0068-the-assistant-finishes-the-job.md) (the
> agent loop, routing, plans, attachments and the Manual / Balanced / Auto mode).

## Who sees it

The button is shown to anyone who holds at least one permission in
`App\Services\Assistant\AssistantAccess::PERMISSIONS`: a view permission of any module
the assistant reads. The server decides and shares the answer as `auth.assistant`, which
the launcher and the [Help Center](./help-center.md)'s articles about the assistant both
read (ADR 0062 — the list used to live in the browser). Self-service alone does not open
it, because every turn spends model quota. The built-in **Staff** role
(`attendance.clock` and `leave.request`) does not see it.

Any page can open it with a question typed through `features/assistant/launcher.ts`
(the Help Center's *Ask the assistant* does). The question waits in the composer; it is
never sent on the person's behalf.

Inside the chat, what a person can do is decided per module and per tool by their
permissions (see [Modules](#modules)).

## How a turn works

`App\Services\Assistant\Assistant::handle()` runs one turn:

1. **Read first.** `Retrieval\Retriever` works out what the turn is about and reads it
   before the model is called (see [Knowing](#knowing-retrieval)). The result is a
   *context brief*.
2. **Route.** `Routing\ToolRouter` picks the modules the message is about (at most 6,
   plus the system guide; see [Routing](#routing)). Only their tools and guidance go
   into the request; every other module the user may use is one line in a catalogue.
3. **Build the prompt.** The system instruction holds the security rules, how to act
   and answer, the mode, today's date, the routed modules' `guidance()`, the catalogue,
   the brief (fenced as untrusted data) and the conversation's
   [attachments](#attachments), by number. Up to the last 20 earlier messages are
   replayed, each capped at 4,000 characters; an assistant turn ends with a line naming
   the steps it took (`[Steps: …]`), so a follow-up knows what "him" refers to.
4. **Loop.** Gemini is called; its tool calls are gated and run (see
   [Doing](#doing-tools)); the results go back; and again — until the model replies in
   text. At most **8** round trips, **15** calls and **5** writes (run or planned) per
   turn. `load_tools` brings another module's tools in for the next round trip. A call
   already made this turn (same tool, same cleaned arguments) is not run again, and a
   step that only repeats earlier calls ends the loop.
5. **Write the reply.** The model's own reply, normally. When a plan is held, the reply
   is composed locally from it ("Nothing has changed yet — these 2 changes wait for
   your OK, in order: …"), because the model's words may claim it is done. When the
   model stops without writing, the reply is composed from the result cards.
6. **Guard the reply.** `Security\ReplyGuard` removes images and unlinks anything that
   leads off the app.
7. **Persist.** `AssistantController` stores the user message (with its files) and the
   reply (with its steps, result cards and `usage`) in the conversation
   ([assistant tables](../database/assistant-tables.md)).

**Cost.** A request carries roughly 10–25 KB of tools and guidance instead of the
~155 KB every request carried before ADR 0068, so a two- or three-request turn costs
less than one request used to. Each turn's requests and tokens (prompt, output,
cached, thinking) are summed from Gemini's `usageMetadata` and shown under the reply
("2 model calls · 9.8k tokens").

## Routing

`ToolRouter::select()` scores each available module against the message, locally:

- its **hints**, a curated word list per module (`cv`, `résumé`, `vacancy` →
  recruitment; `leave`, `vacation` → leave), whole words, plurals included, or phrases;
- the subject words of its **tool names** (`find_job_postings` → job, posting);
- **used in the last assistant turn** (from the cards' `module`), so follow-ups keep
  their tools;
- **accepts attachments** (recruitment, employee records) when files are present;
- **Employees**, when the brief is about a person.

The best six are loaded; the system guide always is. The model calls
`load_tools(modules)` — answered by the orchestrator, its argument an enum of the
user's *unloaded available* modules — to bring any other in. A refused load names
nothing.

Routing decides what is shown, **never what may run**: a call to any tool the user's
permissions offer runs (with its arguments cleaned to its schema) whether or not it was
shown this turn; a call to anything else is refused and logged as before.

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

The person chooses a **mode** in the composer (sent as `mode` with each message,
remembered in the browser as `synapse.assistant.mode`):

| Mode | A write waits for Confirm when… |
| --- | --- |
| **Manual** | always. |
| **Balanced** (default) | the tool is in its module's `confirmTools()`, the turn was a question, or the turn carried an attachment. |
| **Auto** | the tool is in its module's `confirmTools()`, or the turn was a question. |

In every mode `confirmTools()` hold — deleting, archiving, hiring, rejecting,
launching, submitting, granting access, sending a notification, filing a document on a
201 file, and every setting that changes how other people's days are judged — and so
does a write proposed on a question (`RequestIntent::isQuestion()`: greetings and
politeness are stripped first; "can you / could you" + a verb that changes something is
an instruction, while a request to read — "show me…", "can you check…", "give me…" — is
a question whatever its punctuation; English and Filipino). The mode grants nothing; every tool is still
permission-checked. Auto's trade-off — a document written to steer the model could
cause a non-consequential write the user may make — is stated on the switch.

**Plans.** The first held write of a turn starts a **plan**: `Security\PendingActions`
parks it under a token bound to the user, the workspace and the conversation
(single-use, 15 minutes). The turn goes on: every later write queues behind it in the
same plan, and reads still run, so "put this CV in the analyst posting as an offer"
becomes one card — *1. Add application · 2. Move application* — listing exactly what
would run. A module that implements `Contracts\ExplainsConsequences` adds a line on
what a step would reach ("Judges 42 people today; …").

A plan that lapses mid-turn refuses the next write instead of starting a second
plan, so a step can never be confirmed without the step it depends on.

**Confirm** (`AssistantActionController@confirm`) runs the plan through
`Assistant::executePlan()`, in order, and stops at the first step that fails; the reply
names what was done, what failed and what never ran. Each tool must still be offered
to the user and its module re-checks the permission, so confirming is consent, not a
grant. Files a step names resolve against the plan's own conversation. No model call is
made.

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
- **The model never names a file path.** A tool names an attachment by number or name;
  `Attachments\ConversationAttachments` resolves it only among the bound conversation's
  own uploads, owned by the signed-in user, and only under that conversation's folder.
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
| Recruitment | `recruitment` | `recruitment.view` | P | 25: postings, applicants, applications, interviews, `rank_candidates`, `candidate_profile`, `candidate_insights`; deletes, `reject_application`, `withdraw_application`, `hire_applicant`, `cancel_interview` †. `add_applicant`, `add_application` and `update_applicant` file a résumé (`resume_attachment`) and supporting documents (`document_attachments`) from the conversation; `add_application` can place a candidate straight at a `stage`. |
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
| Employee records | `employee-records` | `employees.view` | W | `find_certifications`, `find_employee_documents`, `add_certification`, `remove_certification`†, `add_employee_document`† (from an attachment) |
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

## Attachments

Files sent in chat (pdf, png, jpg, jpeg, webp, txt; up to 8 of 8 MB each) are **kept**,
not just read once:

- stored on the private **`assistant` disk** at
  `<organization_id>/<conversation_id>/<random>.<ext>` — `storage/app/private/assistant-uploads`
  locally, the private exports bucket under `assistant-uploads/` when
  `SUPABASE_EXPORTS_BUCKET` is set; never the public disk;
- numbered across the conversation (`[1] Ana_CV.pdf (PDF, 120 KB, sent with this
  message)`) in the instruction;
- **read by the model** inline on the turn they arrive — Gemini reads PDFs and images
  itself, scans included, which is how a CV's details reach a tool call. A later turn
  that mentions "the CV", "that document", "the attachment" (not "file" or "document"
  alone, which are usually verbs) and sends none gets the most recent earlier file
  re-sent; regenerating re-sends the message's own files. A
  request carries at most ~14 MB of files;
- **filed by reference**: a tool's attachment parameter takes a number or a file name,
  and the file is copied onto the record's own disk under the screen's own type and
  size rules (`ApplicantDocumentStore::resumeFrom()` / `documentFrom()`,
  `Support\Employees\EmployeeDocuments`);
- deleted with their conversation, and when history is cleared.

Module tests are the `*AssistantTest.php` files beside each module's own tests
(`tests/Feature/<Module>/`). Cross-cutting behaviour is in
`tests/Feature/Assistant/AssistantGuardTest.php`, `AgentLoopTest.php`, `RoutingTest.php`,
`AttachmentsTest.php`, `GeminiClientTest.php`, `RetrievalTest.php` and
`SystemGuideTest.php`, `tests/Unit/Assistant/RequestIntentTest.php`,
`tests/Feature/Recruitment/RecruitmentAttachmentAssistantTest.php`, and
`tests/Feature/Employee/AssistantEndpointSecurityTest.php`. Tests bind the scripted
model `fakeAssistantModel()` from `tests/Pest.php`; none needs or spends an API key.

## Endpoints

All under `auth` + `verified`, in `routes/web.php`:

| Method | Path | Controller | Notes |
| --- | --- | --- | --- |
| POST | `/assistant` | `AssistantController@send` | `message` (≤ 4,000 chars), `conversation_id`, `replace_message_id` (edit and resend), `mode` (`manual`, `balanced`, `auto`; default `balanced`), up to 8 `files` (pdf, png, jpg, jpeg, webp, txt; 8 MB each), which are kept. Throttle `assistant`. |
| POST | `/assistant/conversations/{id}/regenerate` | `AssistantController@regenerate` | Re-runs the last user message, with its files; takes `mode`. Throttle `assistant`. |
| GET | `/assistant/conversations` | `AssistantConversationController@index` | The user's threads in this workspace, pinned first. |
| GET | `/assistant/conversations/{id}` | `…@show` | One thread with its messages. |
| PATCH | `/assistant/conversations/{id}` | `…@update` | Rename or pin. |
| DELETE | `/assistant/conversations/{id}` | `…@destroy` | Deletes its files too. |
| DELETE | `/assistant/conversations` | `…@clear` | Deletes all of the user's threads in this workspace, and their files. |
| POST | `/assistant/actions/confirm` | `AssistantActionController@confirm` | Runs a held plan, in order. Throttle `assistant-actions`. |
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

`resources/js/features/assistant/`. The panel is mounted once in
`layouts/app/app-sidebar-layout.tsx`, and its button in `components/app-sidebar-header.tsx`
(ADR 0067):

- `launcher.ts` — whether the panel is open (`useAssistantOpen`, a small external store
  the button and the panel share), and `open(prompt)` for any page to open it with a
  question typed.
- `use-assistant.ts` — state and orchestration: the thread list, the active thread,
  optimistic sending, the short written-out reveal of a reply, edit, regenerate, retry,
  and answering confirmation cards.
- `api.ts` — `fetch` calls with the XSRF header, and the server's own reason when a
  request fails; `types.ts`.
- `components/assistant-button.tsx` — the top-bar button and the ⌘J / Ctrl+J shortcut.
- `components/assistant.tsx` — the panel: docked beside the page at ≥ 1280 px, a sheet
  from 640 px, the whole screen below that; two widths (`synapse.assistant.wide`);
  Escape and focus; dropping files anywhere on it; moving the toasts aside while it is
  open; an unsent draft per thread in `localStorage` (`synapse.assistant.draft.<id>`).
- `components/message-list.tsx`, `message-item.tsx` — the turns, the empty state's *Ask*
  and *Do* starting points, copy, edit and regenerate, *Working* with the elapsed
  time, and what a turn cost.
- `components/agent-activity.tsx` — the work trace (a hollow node for a read, filled for
  a change, amber for held, red for an error; folded past three steps), the receipts,
  and the Confirm / Cancel card, which numbers a plan's steps.
- `components/assistant-mark.tsx` — the mark: a two-step trace in miniature.
- `components/composer.tsx` — input, attachments, the mode, Send and Stop.
- `components/mode-menu.tsx` — Manual / Balanced / Auto, with what each means.
- `components/conversation-list.tsx` — history: search, rename, pin, and delete or clear
  with an in-place confirmation.
- `components/markdown.tsx` — the reply renderer; it drops images and off-app links,
  as `ReplyGuard` does on the server.

Colours come from `--assistant-ink` (navy), `--assistant-signal` (teal) and
`--assistant-signal-text` (a deeper teal that passes as text) in `resources/css/app.css`.

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `GEMINI_API_KEY` | — | Required. Never sent to the browser. |
| `GEMINI_MODEL` | `gemini-2.5-flash` | `config/services.php`. |
| `GEMINI_TEMPERATURE` | — | Empty = the model's own default (0.2 on `gemini-2*`). Google advises leaving Gemini 3 models at their default; lower can make them loop. |
| `GEMINI_THINKING_LEVEL` | — | `minimal`, `low`, `medium` or `high` (Gemini 3), sent as `thinkingConfig.thinkingLevel` only when set. `low` is cheaper and faster. |

The client retries once when Gemini answers with no candidate or a
`MALFORMED_FUNCTION_CALL`; a blocked answer (`SAFETY` and the like) gets a polite
refusal rather than an error. The model's turn is echoed back unchanged, thought
signatures included, as Gemini 3 function calling requires.

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
   is new, add it to `AssistantAccess::PERMISSIONS`, and give `SystemGuide` an entry
   for the screen.
6. Give it a line in `Routing\ToolRouter::MODULES`: its catalogue summary and the words
   people use for it. Without one it is still routed by its tool names and loadable by
   `load_tools`, but the catalogue line is just its key.
7. If a tool files a chat attachment, take a number-or-name parameter, resolve it with
   `ConversationAttachments::resolve()`, check it against the screen's file rules, copy
   it with `StoredAttachment::copyTo()`, and add the module to
   `ToolRouter::ATTACHMENT_MODULES`.
8. Test with `fakeAssistantModel()` from `tests/Pest.php`, so no quota is spent.
