# Offboarding

The structured exit of a departing employee — the mirror image of
[Onboarding](./onboarding.md). An offboarding **case** opens a **clearance**
checklist routed to the responsible departments; finalising it separates the
employee. The *why* is in [ADR 0016](../decisions/0016-offboarding-and-clearance.md),
with the employee's own resignation and the exit interview in
[ADR 0076](../decisions/0076-employees-resign-and-leavers-answer-an-exit-interview.md)
and the generated papers in
[ADR 0077](../decisions/0077-an-exits-papers-are-generated-as-pdfs.md); this is the
*how*. Everything is tenant-scoped (ADR 0005). Data model is ERD §9.

> Status: **Active** · Route prefix: `/offboarding`
> Sidebar: Offboarding (shown for `offboarding.view` or `offboarding.resign`)

## Where it sits in the life cycle

```
hire ─▶ Onboarding ─▶ … productive tenure … ─▶ Offboarding ─▶ separated
        └ ADR 0007 ┘                            └ this module ┘
```

A case is started by HR for a leaving employee, or by HR **accepting a resignation the
employee filed**. Completing it marks the employee `resigned` / `terminated` — the
canonical way they leave `active`.

## Sections

The header's sections (`OffboardingNav`) are **My resignation ‖ Exits · Resignations
(pending count) · Exit interviews**. Somebody who may only resign sees *My
resignation* alone, and `/offboarding` sends them there.

## Surfaces

Laid out like the Workforce modules and Onboarding (header · compact stat tiles ·
toolbar · table · pagination), from the shared table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md),
[ADR 0048](../decisions/0048-talent-acquisition-and-offboarding-join-the-table-kit.md)):

- **`/offboarding`**: everyone leaving, as **one table**.
  - Four stat tiles: in offboarding, flagged clearances, leaving in 14 days, completed
    this month.
  - The toolbar has search, exit type, department and status filters (*Active* by
    default), Reset, *Export* (with the current filters) and *Start offboarding*.
  - Each row shows the employee, department and position, exit type, status, clearance
    progress (cleared / total, with the flag count) and last working day. A last day an
    exit still in flight has passed shows in red, and a completed exit shows *Done …*.
  - Columns sort by employee, type, status, clearance and last day, and the table is
    paged in the browser.
  - A row opens the case. Its menu opens the clearance, exports its sheet, completes the
    exit (confirmed, naming any outstanding items), reopens it, cancels it (confirmed)
    or deletes it (confirmed).
- **`/offboarding/{case}`**: the **case**.
  - The employee header has status and type badges, *Export sheet*, *Add item* and the
    lifecycle menu (complete, reopen, add from template, clear all pending, edit
    details, cancel, delete).
  - A clearance summary shows the signed-off count, the derived clearance status, the
    notice and last day, the template and flags.
  - The **clearance checklist is a table**. Each department that signs items off has a
    header row with its cleared count and *Clear all (n)*, and Unassigned comes last.
    Each item row has a tick-box, its status, who signed it off and when, and remarks
    (red when flagged). Its menu flags or unflags the item, edits it or deletes it, and
    for managers the row opens the item to edit.
  - Underline tabs **Clearance · Exit interview**. The second shows the survey's state
    (waiting, answered, waived), *Send* or *Waive*, and, once answered, the answers:
    the reason, the seven ratings as a table with the average, would-recommend,
    would-return and the open answers.
  - A **Documents** menu downloads the clearance form (any time) and the Certificate
    of Employment (once completed, with its number).
  - When the exit came from a resignation, the header says so, with the day it was
    filed.
  - Once the exit is **completed**, a note shows when the leaver can still sign in to
    this workspace. They keep that access on purpose, for the exit interview and the
    certificate. **Remove access** (for whoever holds `users.delete` and may archive
    them) is the Users screen's own archive: it removes them from this workspace when
    their account belongs to another one too, and otherwise archives the account
    ([ADR 0089](../decisions/0089-people-start-and-leave-their-own-workspaces.md)).
- **`/offboarding/resignations`** (`offboarding.view`): resignations employees filed.
  - Tiles: waiting, short notice, and accepted and withdrawn in the last 30 days.
  - Search, department and status filters, and a table: employee, filed, proposed last
    day, notice (red under 30 days), status.
  - *Review* opens a modal with the reason. *Accept* sets the last working day (the
    proposed one by default) and an optional clearance template; *Decline* needs a
    note. `?open=<hashid>` opens one (from the notification).
- **`/offboarding/interviews`** (`offboarding.view`): what leavers say, over 3, 6 or 12
  months or all time.
  - Tiles: sent, answered (with the response rate), waiting, average rating, would
    recommend us.
  - **Why people leave** (reason, count, share bar) and **How leavers rate us** (each
    rating's average and spread).
  - Every survey of the period, filtered by status, as a table that opens its exit's
    *Exit interview* tab.
- **`/offboarding/me`** (`offboarding.resign`): **My resignation**.
  - With nothing under way: *Resign* opens a modal for the proposed last day (the
    notice is counted as it is picked, with a note under 30 days) and an optional
    reason.
  - A waiting resignation can be withdrawn. A declined one shows HR's note.
  - Once the exit starts: its dates, the clearance item by item (a flag shows its
    reason), the exit interview (*Answer* while it waits) and the Certificate of
    Employment once ready.
- **`/offboarding/me/exit-interview`**: the survey, with the ratings as a matrix table.
- **Employee detail → Offboarding tab**: a read-only summary of the employee's exit
  (type, status, clearance progress, last day), linking to the case.

## Data model

`offboarding_cases`, `clearance_items` — see the
[schema doc](../database/offboarding-tables.md). Highlights:

- A **case** is one employee's exit (`unique(employee_id)`), with a `type`
  (`resignation | termination | retirement | end_of_contract`), the notice /
  last-working-day dates, a reason, and a lifecycle
  (`initiated → clearance → completed`, or `cancelled`).
- A **clearance item** is one sign-off: a label, an owning `department_id`, a status
  (`pending → cleared`, or `flagged`), the `cleared_by` user + `cleared_at`, and
  `remarks` (the sign-off note / flag reason).
- A case's Certificate of Employment number and first issue: `certificate_no`
  (`COE-<year>-<id>`), `certificate_issued_at`.
- A **resignation request** (`resignation_requests`, hashid) is one the employee
  filed: proposed last day, reason, `pending → accepted | declined | withdrawn`, who
  decided and the note, and the exit it became.
- An **exit interview** (`exit_interviews`, one per case): `pending | submitted |
  waived`, the questions as asked and the answers (JSON), and the sent / reminded /
  submitted / waived stamps.

## Backend

- Controllers (`app/Http/Controllers/Offboarding/`): `OffboardingCaseController`
  (index / show / store / update / status / destroy), `ClearanceItemController`
  (store / update / toggle / destroy / apply-program / bulk-clear). Both are thin:
  every write goes through **`App\Support\Offboarding\OffboardingWorkflow`**, the
  path the assistant takes too. Refusals are an `OffboardingException`, whose message
  is the toast.
- **`App\Support\OffboardingProvisioner`** — the connective tissue (the
  `OnboardingProvisioner` analogue). `start()` opens a case and instantiates the
  **standard clearance checklist**, routing each item to its department (IT / Finance /
  HR by code, or the employee's own department); idempotent per employee. It is also
  the single source of truth for the **derived clearance status** (`clearanceStatus()`).
- **`App\Support\Offboarding\ResignationWorkflow`** — file, withdraw, accept (it
  becomes the exit through `OffboardingWorkflow::start`, or reopens a cancelled one)
  and decline; `settleFor()` marks a waiting resignation accepted when HR starts the
  exit by hand.
- **`ExitInterviews`** — send (automatically with every exit but a termination, or by
  hand), submit (signs off pending items mentioning "exit interview"), waive, and
  `sendDueReminders()` for `offboarding:exit-interview-reminders` (daily 09:00, once
  after 3 days). **`ExitInterviewQuestionnaire`** — the fixed survey, its validation
  and `summarize()`.
- **`OffboardingDocuments`** — the clearance form and the Certificate of Employment
  as PDFs (Dompdf, remote fetching off, logo embedded). **`MyExit`** — one person's
  view of leaving, for the web and the app.
- Controllers `ResignationController`, `ExitInterviewController`,
  `MyOffboardingController` and `OffboardingDocumentController` share the
  `OffboardingResponses` trait (toast on an `OffboardingException`, nav counts).
- The app: `Api\OffboardingController` (`/api/offboarding`, `resignation`,
  `resignation/{id}/withdraw`, `exit-interview`, `certificate-link`, all
  `can:offboarding.resign`) and `Public\CertificateDownloadController` (the signed,
  10-minute certificate link).
- Requests under `app/Http/Requests/Offboarding/`; resources `OffboardingCaseResource`
  (with a derived `clearance` summary) + `ClearanceItemResource`; queries
  `OffboardingCasesIndexQuery` (filtered, with clearance counts — no pagination, the
  board is card-based) and `OffboardingStatistics`.
- `routes/offboarding.php` (literal-prefixed `clearance/…` routes precede the `{case}`
  wildcard). Every route is permission-gated. Cases are addressed by **hashid**
  (`App\Support\Hashid`, via `HasHashid`); items by numeric id (sub-resources).
- Mutations are activity-logged (`logName: 'offboarding'`). That now includes
  editing an exit's details and every clearance write (add, edit, sign off, flag,
  reset, remove), none of which were logged before. The lifecycle lines read
  "Completed / Cancelled / Reopened"; before, they read "Canceld" and "Reopend".
- Validation lives in FormRequests (`UpdateOffboardingCaseRequest`,
  `OffboardingStatusRequest`, `ClearanceStatusRequest`, `ApplyClearanceTemplateRequest`,
  `BulkClearClearanceRequest`). Every id is confined to the workspace (`TenantRule`):
  before, a clearance item or a template item could be saved against another
  organisation's department. A last working day before the notice date is refused.

### Clearance status & lifecycle

- **Derived clearance status** — `OffboardingProvisioner::clearanceStatus()` returns
  `pending` (untouched) / `in_progress` (some signed off) / `cleared` (all signed off)
  from the item counts. Never stored, so it cannot drift (the onboarding-progress norm).
  `flagged` items keep a case off `cleared`.
- **Auto-advance** — an `initiated` case **nudges to `clearance`** on the first sign-off
  or flag, so the board reflects activity without a manual status change (exactly as
  onboarding nudges `pending → in_progress`).
- **Completion bridge** — completing the exit stamps `completed_at` and transitions the
  employee's `employment_status` to match the type; reopening / cancelling returns them
  to `active`. The complete action confirms the change (and warns of any pending items).
- **Guarded lifecycle** — only an exit in progress can be completed or cancelled, and
  only a completed or cancelled one reopened. Before, a completed exit could be
  "completed" again, re-stamping its date. An exit cannot be started for someone who
  is already resigned or terminated. Deleting an exit leaves the employment status
  alone.

## Frontend

`features/offboarding/` holds the types, routes, constants (status, type, clearance,
resignation and interview meta, and day arithmetic) and the filter hook, and these
components:

- `offboarding-nav` (the header sections), `status-pill`;
- `resign-dialog`, `resignation-review-dialog`, `case-interview-panel`;

- `offboarding-stats` (stat tiles);
- `case-table` and `case-row-actions`;
- status and type badges, and the progress bar;
- `clearance-table`, grouped by department;
- a confirm dialog;
- four forms, all **centred modals** on the shared `components/modal.tsx` shell with
  `FormField` / `FormSelect`, the same as Onboarding: `initiate-offboarding-dialog`,
  `clearance-item-form-dialog`, `case-settings-dialog` and `apply-program-dialog`.

Pages are `pages/offboarding/index.tsx`, `case.tsx`, `resignations.tsx`,
`interviews.tsx`, `me.tsx` and `exit-interview.tsx`. PDFs are rendered from
`resources/views/documents/`. The clearance templates'
screen (`programs-manager`, `program-form-sheet`) belongs to Company Setup. The sidebar
**Offboarding** link shows for `offboarding.view` or `offboarding.resign`.

## Permissions

`offboarding.view` (overview + detail, resignations, exit-interview answers and the
papers) and `offboarding.manage` (start exits, manage clearance, lifecycle, decide
resignations, send or waive surveys). `offboarding.resign` (ADR 0076) is one's own
exit: file and withdraw a resignation, follow the clearance, answer the survey,
download the certificate. Built-in **HR Manager** gets all three; **Department Head**
and **Staff** get `offboarding.resign`; Super Admin / Administrator get them via the
all-permissions grant.

## Seeding

`OffboardingSeeder` (in `DatabaseSeeder`) seeds 5 exits across the
lifecycle — completed (employee separated), in clearance, in clearance with a flagged
item, just initiated, and cancelled — each with the standard 10-item clearance
checklist, on active employees. It adds exit interviews to up to 40 past exits (other
than terminations; answered for those completed within a year) and two waiting
resignations, one with short notice. Idempotent: it seeds when no exit is in flight — the
completed cases the workforce history leaves behind (`WorkforceHistorySeeder`, about
230 past departures for [model graduation](./model-graduation.md#demo-history)) are the
past, not the board's demo cases.

## The assistant

`App\Services\Assistant\Modules\OffboardingModule` puts exits in the chat assistant
([ADR 0051](../decisions/0051-assistant-offboarding-reports-and-workspace-members.md)).

- **Reads** (`offboarding.view`):
  - `find_offboarding_cases` — the board's own query (`OffboardingCasesIndexQuery`,
    now with a request-free `filtered()`), in progress by default;
  - `get_offboarding_case` — one person's exit and every checklist item. Its reason may
    be a termination's, so reading it is audited as `viewed`;
  - `find_clearance_items` — sign-offs across exits in progress, by status (pending by
    default), department or person ("what does IT still owe?");
  - `offboarding_summary` — the board's tiles, who leaves next, what is flagged.
- **Writes** (`offboarding.manage`), all through `OffboardingWorkflow`:
  - `update_offboarding_case`, `add_clearance_item`, `update_clearance_item`,
    `set_clearance_status` (sign off, flag with a reason, or reset) and
    `apply_clearance_template` run on a plain instruction;
  - **these always wait for Confirm:**
    - `start_offboarding` — it tells the organisation somebody is leaving;
    - `set_offboarding_status` — completing separates the employee, and the reply
      says how many items were never signed off;
    - `delete_offboarding_case`;
    - `clear_pending_clearance` — many sign-offs in the user's name;
    - `remove_clearance_item`.
- An exit is identified by its employee, and a checklist item by (part of) its label.
  Each resolves to exactly one or not at all.
- **Retrieval:**
  - a question about a person carries their exit, the reason, and what is flagged or
    pending;
  - "who is leaving soon?" carries the board.
- **Resignations and exit interviews** (ADR 0076): `find_resignations` and
  `get_exit_interview` (audited as `viewed`) and `exit_interview_summary` read;
  `send_exit_interview` runs on a plain instruction; `decide_resignation` (accept with
  the last day agreed and an optional template, or decline with a note) and
  `waive_exit_interview` wait for Confirm.
- **One's own exit** (`offboarding.resign`): `get_my_exit` reads what My resignation
  shows; `file_my_resignation` and `withdraw_my_resignation` wait for Confirm. The
  survey is answered on its page or in the app, not in chat; the papers are downloaded,
  not made in chat.

### Clearance templates in the assistant

`App\Services\Assistant\Modules\OffboardingProgramsModule`, gated by
`offboarding.manage-programs` like the screen
([ADR 0056](../decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md)).
Every write goes through **`App\Support\Offboarding\OffboardingProgramWorkflow`**,
which the Offboarding Programs screen uses too.

- **Reads:** `find_clearance_templates`; `get_clearance_template`, its sign-offs and
  owners, and whose exit it would seed today (`OffboardingProvisioner::programFor()`).
- **Writes:**
  - `create_clearance_template`, from items (each owned by a department, "own", or
    nobody), by copying one, or from the built-in standard list. It is never the
    default at creation;
  - `set_clearance_template_item` (add, reword, re-own) and
    `remove_clearance_template_item`. Exits in flight keep their checklist.
- **Confirmed:** `update_clearance_template` (name, description, department, exit
  type, active, default) and `delete_clearance_template`. The card says whose exit the
  template would seed and how many exits in flight came from it.
- A template name another has in any case is refused.
- **Retrieval:** "what is on our exit checklist?" carries the templates and how one is
  chosen.

## Out of scope (this cut)

Final-pay computation from the case; questions of the company's own in the exit
interview; a Certificate of Employment for people still employed; templates and
letterheads per document; answering the survey in chat.
