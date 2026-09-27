# Assistant: Dashboard and Performance, and defences against prompt injection

The assistant gains retrieval (RAG) and function calling for two more modules,
**Dashboard** and **Performance Management**. Every module's assistant surface is also
hardened on the assumption that the model will be prompt-injected, through text in a
record, a CV or a tool result. See
[ADR 0049](../decisions/0049-assistant-prompt-injection-defences.md).

## Highlights

- **"How are we doing today?"** is answered from the live dashboard, gated block by
  block exactly as the dashboard is.
- **"How is Maria's appraisal looking?", "how is the review cycle going?"** are answered
  from the appraisals and the cycle read. HR can open, rate, submit, sign off and
  delete appraisals, or launch a cycle, from the chat.
- **Consequential actions wait for a Confirm click.** The card shows exactly what will
  run, so nothing is archived, hired, rejected, deleted, submitted or launched on the
  model's word alone. The same holds for any change proposed on a question or while a
  document is attached.
- **An injected model is contained:**
  - it can only call tools it was offered;
  - its arguments are held to each tool's schema;
  - every string from a record is cleaned and fenced with a random per-turn marker;
  - a turn has a budget of calls and writes;
  - a reply cannot carry an image or an external link.

## Backend

### New modules

- **`PerformanceModule`:**
  - reads: `find_appraisals`, `get_appraisal` (audited as `viewed`),
    `performance_summary`, `list_review_cycles`;
  - writes: `open_appraisal`, `rate_appraisal` (by criterion name; a number on its own
    scale or a level name; off-scale ratings are refused), and `submit_appraisal`,
    `acknowledge_appraisal`, `delete_draft_appraisal` and `launch_review_cycle`, which
    always need confirmation;
  - retrieval: a person's last four appraisals, plus their forecast only with
    `analytics.performance.view`; and a cycle topic;
  - names must resolve to exactly one person, and no self-service view exists, matching
    the screens.
- **`DashboardModule`:**
  - tools: `get_workspace_overview`, `get_attention_queue`, `list_upcoming_events`,
    `get_recent_activity`, `get_attendance_trend`;
  - a workspace topic for questions that name nobody;
  - built on `DashboardOverview`, and read-only.
- **`Contracts\ContributesTopicContext`:** modules can brief the workspace, not only a
  person. `Retriever` falls back to it when a turn names nobody, matching trigger words
  as whole words in the user's own message. `ContextBrief` can now describe the
  workspace.

### Shared appraisal workflow

`Support\Performance\AppraisalWorkflow` handles open, rate, submit, acknowledge, discard
and launch. Supporting it are `AppraisalException` and `CycleLaunch`, whose
`message()` produces the launch toast. Both Performance controllers are now thin and
call it, and so does the assistant. Messages and behaviour are unchanged.

### Security (`Services\Assistant\Security`)

- `UntrustedText` cleans text, removing control and format characters, line and
  paragraph separators, and fence markers, and capping length. It is applied to every
  retrieved line and every function-response string, and `EmployeeDisclosure::text()`
  now uses it.
- `PromptFence` draws a random, per-turn data fence around the retrieved brief.
- `ToolArguments` keeps only declared parameters, in their declared types, and enforces
  enums and caps.
- `PendingActions` parks held calls. Each token is single-use (claimed atomically),
  bound to the user, workspace and conversation, hashed at rest, and valid for 15
  minutes.
- `ReplyGuard` removes images, unlinks external links and drops reference definitions.
- **`Assistant`:**
  - an allow-list of offered tools, with a refused call audited as
    `assistant · blocked`;
  - argument cleaning;
  - per-turn caps of 10 calls and 3 writes;
  - the hold rules (consequential tool, question turn, or attachment);
  - discarding the model's own text when an action is held;
  - `execute()` for confirmations, which re-authorises the call;
  - a security block at the top of the system instruction;
  - history turns cleaned and capped;
  - an untrusted-content notice before attachments.
- `AssistantModule` gains `isReadOnly()` and `requiresConfirmation()`. Each module
  declares its `confirmTools()` (and `readTools()` where a read's name does not say
  so).
- **`AssistantActionController`** adds `POST /assistant/actions/confirm` and
  `POST /assistant/actions/cancel`. The token travels in the body, the route is limited
  by `throttle:assistant-actions` (30/min), and every failure gets the same 410.
  Confirming persists the result as a new assistant turn, marks the card answered and
  removes the spent token.
- **Existing modules:**
  - `find_attendance` and `find_shifts` no longer look up a name for a user who may not
    see it. Such a user gets the same answer for a colleague as for a made-up name, and
    can read their own record.
  - The CV insight and appraisal insight prompts now treat their input as untrusted
    and report attempts to steer them. Candidate-typed fields are cleaned.

### Fixed

- **`EvaluationOpener::lines()` did not order scorecards section by section.** It gave
  `sortBy()` a list of one-argument key closures, which Laravel calls as two-argument
  comparators. It now uses one explicit comparator, pinned by a test.

## Frontend

- **Confirmation card** (`agent-activity.tsx`):
  - shows the action and its exact arguments, the reason it waits, and Confirm and
    Cancel buttons;
  - the card changes to Confirmed, Cancelled or Expired, and stops offering itself when
    the token lapses.
  - Held steps show an hourglass.
- `answerAction()` in `api.ts` and `use-assistant.ts` updates the card in place and
  appends the turn that reports what was done.
- **`Markdown`** renders no images, and links only to the app's own `/…` paths.
  External URLs appear as plain text with their host.
- New empty-state suggestions: "How are we doing today?" and "How is the review cycle
  going?".
- **The chat button** now also appears for `performance.view` and for every dashboard
  block's permission (`attendance.view`, `offboarding.view`, `events.view`,
  `activity-logs.view`, `leave.manage`). Self-service alone still does not open it,
  since every turn spends model quota.
- **Only real changes toast and reload the page.** Read-outs (`insight`) and held
  actions (`confirm`) used to be counted as mutations, which reloaded the page for a
  summary.

## Verification

- **Pest:** the full suite passes (1168 tests: the previous 1112 plus 56 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database, with a real held
  action planted in a conversation:
  - the confirmation card renders in light and dark mode;
  - Confirm archived the employee, the card turned Confirmed, the result turn and
    toast appeared, and the dashboard refreshed;
  - after a reload the card stayed answered, with no buttons;
  - an injected Markdown image and external link rendered as plain text, and no
    request ever reached their host; the internal `/leave` link stayed a link;
  - no console errors.

## Tests

- `Assistant/AssistantGuardTest` (21 tests):
  - the cleaning, argument and reply guards;
  - refused tools and the audit entry;
  - the fence (present, and different every turn);
  - cleaned function responses;
  - the hold rules;
  - write and call caps;
  - confirm, cancel, replay, another user's token, another workspace, malformed tokens
    and revoked permissions;
  - the attendance oracle.
- `Performance/PerformanceAssistantTest` (22 tests): permissions, reads and the audit
  entry, tenant isolation, rating by name and level, off-scale and ambiguous-name
  refusals, the lifecycle, launch by department, the person brief and its gating, the
  forecast gating, the cycle topic, and scorecard ordering.
- `Dashboard/DashboardAssistantTest` (13 tests): availability, per-block gating, the
  audit-trail permission, the event window, the empty queue, topic retrieval and its
  gating, whole-word triggers, and a hijacking event title arriving flattened and
  fenced.
- `Assistant/RetrievalTest`: its scripted model now passes each tool's declared
  argument, since undeclared ones are dropped.

## Notes

- **Stricter arguments.** A tool call that uses an undeclared parameter name no longer
  reaches the handler. Every declaration names its parameters, and those are what
  Gemini is given.
- **Pending confirmations live in the cache**, which is configured as the `database`
  store. There, `Cache::add` is an atomic insert, and the single-use claim relies on
  that. A non-persistent store (`array`) would forget a held action between requests.
