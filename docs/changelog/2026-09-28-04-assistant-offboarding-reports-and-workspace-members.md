# Assistant: Offboarding and Reports, and users picked from the workspace

The assistant gains retrieval (RAG) and function calling for **Offboarding** and
**Reports**, the last two modules without it. Auditing them turned up a
cross-workspace problem wider than either module. Users are identities shared across
workspaces, so every place that picks a user must ask for this workspace's members.
Several did not, including "Everyone" announcements, which went to every company on
the instance. See
[ADR 0051](../decisions/0051-assistant-offboarding-reports-and-workspace-members.md).

## Highlights

- **"Who is leaving this month?", "what does IT still need to sign off?", "how is
  Maria's clearance going?"** are answered from the board and the checklist. HR can
  start, edit, complete, cancel, reopen and delete exits, and sign off, flag, add or
  remove clearance items, from the chat.
- **Anything that changes who works here waits for a Confirm:** starting an exit,
  completing, cancelling or reopening it, deleting it, signing off everything pending,
  and removing an item.
- **"What's our turnover this year?"** is answered from Workforce Movement before the
  model is called. Any report the user may open can be run from the chat, with the
  report's own totals and a link that reopens exactly that run.
- **Announcements, messages, assignees and interviewers stay inside the workspace.**

## Backend

### New modules

- **`OffboardingModule`:**
  - reads: `find_offboarding_cases`, `get_offboarding_case` (audited as `viewed`),
    `find_clearance_items`, `offboarding_summary`;
  - writes: `update_offboarding_case`, `add_clearance_item`, `update_clearance_item`,
    `set_clearance_status`, `apply_clearance_template`;
  - confirmed: `start_offboarding`, `set_offboarding_status`,
    `delete_offboarding_case`, `clear_pending_clearance`, `remove_clearance_item`;
  - retrieval: a person's exit, and the board as a topic;
  - an exit is identified by its employee, an item by its label; each resolves to
    exactly one.
- **`ReportsModule`** (read-only):
  - `list_reports`;
  - `run_report` — the report is an enum of the user's keys, the permission is
    re-checked, filters are held to what the report declares, and the result carries
    the totals, charts, ML signals, up to 10 rows and the workspace link;
  - retrieval: the runnable reports and the last twelve months of workforce movement.

### Offboarding, rebuilt on one path

- **`Support\Offboarding\OffboardingWorkflow`** (with `OffboardingException`) holds
  every write, and both controllers are thin callers.
- **The lifecycle is guarded:**
  - only an exit in progress can be completed or cancelled, and only a closed one
    reopened;
  - an exit cannot be started twice, or for somebody already resigned or terminated;
  - deleting leaves the employment status alone.
- **Everything is audited.** Editing an exit and every clearance write were not logged
  before. The lifecycle lines read "Cancelled" and "Reopened", not "Canceld" and
  "Reopend". Descriptions are built by closures, so an item called "100% of the kit"
  is never a format string.
- **Five FormRequests replace the inline validation:** `UpdateOffboardingCaseRequest`,
  `OffboardingStatusRequest`, `ClearanceStatusRequest`,
  `ApplyClearanceTemplateRequest` and `BulkClearClearanceRequest`. A last working day
  may not precede the notice date.
- **`OffboardingCasesIndexQuery::filtered()`** gives the board's query to a caller
  without a request.

### Reports

- **`Support\Reports\ReportParameters`** resolves filters for the workspace and the
  assistant:
  - a select takes a declared option, by value or label (before, any string reached
    the query);
  - dates must be real dates;
  - search is capped;
  - problems are reported. The workspace falls back to the defaults, and the assistant
    refuses.
- **`ReportInsights`:**
  - a security block, and every digest value cleaned (applicant names come from the
    public careers page);
  - answers are plain text whatever shape the model returned. Before, a non-string
    `headline` threw.

### Cross-workspace fixes

- **`Notifier::toAll()`** reached every active user on the instance. It now reaches
  the workspace's members.
- **The notification compose page** listed every active user on the instance, with
  email, to `notifications.send` holders, and sent to any id. It now lists members,
  and the send resolves the recipient among them.
- **`TenantRule::member()`** is the rule for "a user of this workspace". It replaces
  `exists:users,id` for:
  - an onboarding task's assignee (who is notified);
  - an interviewer;
  - an employee's linked account;
  - a notification's recipient.
- **`TenantRule::exists`** now also covers:
  - offboarding: employee, template, clearance-item and template-item departments;
  - onboarding: employee, program, program department;
  - recruitment: job-posting pipeline, department and position, and the applicant;
  - roles: users, bulk assign, notifications.

  Several of these were stored raw. The last changelog's note that the remaining
  unscoped rules were harmless was wrong for them.
- **`Module::resolveMember()`** resolves an assignee or interviewer in the assistant
  among the workspace's active members, exactly one or none. The Onboarding and
  Recruitment modules searched every user on the instance, would assign and notify a
  stranger, and confirmed the name existed. An unknown interviewer is now an error,
  not a silently unassigned interview.
- **Imperatives** gain offboard, complete, reopen, flag, clear and apply.

## Frontend

- **Suggestions** add "Who is leaving this month?" and "What's our turnover this
  year?", ordered so the first six anyone sees span modules.
- **The intro copy** mentions reports and exits.

## Verification

- **Pest:** the full suite passes (1256 tests: the previous 1222 plus 34 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database, with a held
  "complete this exit" planted through the real assistant and a scripted model:
  - the new suggestions and copy show in light and dark;
  - the card waits for Confirm;
  - confirming it moved the employee to `resigned`, audited "Completed offboarding
    for … via assistant", and toasted;
  - no console errors.

## Tests

- **`Offboarding/OffboardingAssistantTest` (16 tests):**
  - permissions, run-time refusal and confirmations, and a plain "start offboarding"
    held;
  - start (seeding, audit, once only, date order);
  - complete (separation, the outstanding count) and the guarded cancel/reopen;
  - delete keeping the status;
  - sign-off and flag, with the stamp, the audit and the nudge into clearance;
  - ambiguous item labels;
  - department-scoped bulk sign-off leaving flagged items;
  - add, edit and remove, and a foreign department refused;
  - the read-out, audited `viewed`;
  - pending items by department;
  - tenant isolation;
  - the person brief and its gating, and the board topic.
- **`Offboarding/OffboardingTest` (5 tests):**
  - web start, with the second-start and already-left refusals;
  - date order on update;
  - the lifecycle toasts, guard and audit wording;
  - clearance audit and a foreign department;
  - template and bulk messages, and a foreign template.
- **`Tenancy/CrossTenantUsersTest` (5 tests):**
  - announcements stay in the workspace;
  - the compose list, and messaging a stranger;
  - assigning an onboarding task to a stranger;
  - linking an employee to a stranger's account, and a posting to another workspace's
    pipeline;
  - the assistant's assignee resolution.
- **`Reports/ReportsAssistantTest` (8 tests):**
  - availability and the offered keys;
  - an unoffered report refused;
  - the totals, charts and link;
  - label-to-value filters;
  - unknown values, undeclared filters and bad dates refused;
  - the workspace no longer passing an undeclared value;
  - the turnover topic;
  - the AI read's cleaned digest and plain-text answer.

## Notes

- **Nothing stored is rewritten.** A task assigned, or a key saved, across workspaces
  before this change stays as it is. Only a tampered request could have produced one.
- **The assistant now covers every module:**
  - Employees, Leave, Attendance, Onboarding, Recruitment;
  - Performance, Training, Awards, Events, Offboarding;
  - Dashboard and Reports.
