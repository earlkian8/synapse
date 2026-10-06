# 0068 — The assistant finishes the job: a real agent loop, tools routed per turn, plans confirmed as one, attachments that can be filed, and a Manual / Auto mode

- **Status:** Accepted
- **Date:** 2026-10-05
- **Extends:**
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md)
    (every rule there still holds; the held call becomes a held plan);
  - [0035 — The assistant answers from a retrieved brief](./0035-assistant-answers-from-a-retrieved-brief.md);
  - [0059 — The assistant covers the whole system](./0059-assistant-covers-the-whole-system.md)
    (its "next step for cost is routing" is taken here).
- **Related:** module doc: [Assistant](../modules/assistant.md).

## Context

A recruiter attached a CV and wrote *"put this person in cybersecurity analyst as an
offer"*. The stored turn shows what happened: the model called `find_job_postings`, the
search succeeded, and the reply was the search result. Nobody was added and the CV was
dropped. Four defects stacked up:

1. **The turn ended after its first step.** `Assistant::handle()` had a cost saver: when
   every call in a step succeeded, it composed the reply from the result cards and
   stopped, without returning to the model. That is right for "approve Maria's leave"
   and wrong for anything with more than one step. The model had planned find → add →
   move, and the loop cut it off at the find.
2. **Chat could not put a file on a record.** The applicant tools had no file parameter
   ("the file rules need an upload, which chat has no way to provide"). Uploads were
   sent to the model once and then thrown away, and only their names were stored.
3. **A held write ended the turn.** A turn with an attachment holds its writes for
   Confirm (ADR 0049), and a held call stopped the loop. So "add, then move to Offer"
   could never be proposed in full, and confirming the first step did not carry out the
   rest.
4. **Every request carried the whole system.** An HR manager's request carried 276 tool
   declarations and 33 guidance paragraphs: about 155 KB, or roughly 40 k tokens, on
   every round trip. That was why the code avoided a second request at all costs, which
   caused defect 1.

Smaller defects: *"hey, can you put…"* counted as a question (writes were held, and
the reply was written up as an answer), and "put" was not on the list of instruction
verbs. Temperature was pinned at 0.2, which Google advises against for Gemini 3 models
(it can cause looping). Nothing recorded what a turn cost in tokens.

## Decision

**The assistant keeps working until the request is done, and each step costs as little
as it can.** Production use means it does what was asked, in as many steps as that
needs, inside the same security walls.

### 1. A real agent loop

After tool results come back, the model is always called again. The turn ends when the
model replies in text, or at a ceiling: **8 round trips, 15 calls and 5 writes** per turn
(up from 6, 10 and 3, so that "add and move" or "approve these three" fit). The locally
composed reply stays as the fallback, used when the model stops without writing.

A **repeat guard** stops a call that was already made in the turn (same tool, same
cleaned arguments). It is not run again, and its earlier result is reused. Models
sometimes loop, and a write must never run twice.

### 2. Tools are routed per turn

`Routing\ToolRouter` decides which modules' tools a turn carries. It runs locally, at no
model cost, and selects:

- modules whose **hints** match the user's words: a curated synonym list per module
  ("cv", "résumé", "vacancy" → recruitment), plus the words in its tool names;
- modules used in the **last assistant turn**, so follow-ups ("now move him to
  interview") keep their tools;
- modules that **accept attachments**, when files are present;
- **Employees**, when the retrieved brief is about a person;
- the **system guide**, always (it is small).

At most six modules are selected, ranked by score. Every other module the user may use
appears in a one-line **catalogue** in the instruction. The model can bring any of them
in with **`load_tools(modules)`**, a tool the orchestrator answers itself; the newly
loaded modules' tools and guidance arrive on the next round trip. A typical request
carries 10–25 KB instead of 155 KB, so a second or third round trip now costs less than
the single request used to.

**Routing only decides what is shown, never what is allowed.** The allow-list is still
"every tool this user's permissions offer" (ADR 0049, rule 1). A call to a permitted tool
that was not shown this turn still runs, with its arguments cleaned to its declared
schema. A call to anything else is refused and logged, as before. `load_tools` only
accepts keys of modules available to the user, so it cannot reveal a module they lack.

### 3. A held write starts a plan; the plan is confirmed as one

When a write is held, the turn continues. **Every later write in that turn is queued
behind it** in the same plan, in order. Reads still run. The model is told each queued
step has *not* run and will run after Confirm, so it can finish proposing the rest of the
request.

The chat shows **one card** listing the plan's steps. **Confirm** runs them in order
through `Assistant::execute()`, stops at the first failure, and reports every step,
including the ones that never ran. Each step is authorised again when it runs, as before.
The token is bound exactly as before: to the user, the workspace and the conversation,
single use, 15 minutes. A plan holds at most as many steps as the write budget (5).

Whatever the model writes after a plan is held is discarded, as before. The reply is
written locally and says nothing has changed yet.

### 4. Attachments are stored, numbered, readable and fileable

- **Stored privately.** Each upload is kept on the private `assistant` disk, under the
  workspace and conversation. Locally that is `storage/app/private/assistant-uploads`. In
  production it is the private exports bucket when one is configured, never the public
  disk. The user message's `attachments` column now holds `{name, mime, size, path}`
  instead of a bare name, and older rows still read.
- **Read by the model.** On the turn a file arrives, it is sent inline. Gemini reads PDFs
  and images natively, scans included, so this is the OCR step: it is how "this person"
  in a CV gets a name, email, phone, headline and years of experience. A later turn that
  refers back to a file ("the CV", "that document", "the attachment") and sends no new
  file gets the most recent earlier upload re-sent, up to the same size cap.
- **Numbered.** The instruction lists the conversation's files as
  `[1] Bancayrin_Curriculum_Vitae.pdf (PDF, this message)`.
- **Filed by reference.** A tool that takes a file declares an attachment parameter, with
  a number or a file name. `Attachments\ConversationAttachments` resolves it **only among
  the current conversation's own uploads**, which belong to the signed-in user in the
  bound workspace. The model never supplies a path. The file is copied onto the record's
  own disk (the public disk, as the screen does), and the destination's own type and size
  rules apply.
  - `add_applicant`, `add_application`, `update_applicant`: `resume_attachment`, and
    `document_attachments` for supporting files (with a type);
  - Employee records gains `add_employee_document` (always confirmed), `employees.manage-documents`.
- **Deleted with the conversation**, and when all conversations are cleared.
- **A Confirm replays the stored reference**, resolved against the plan's conversation.

A turn that carries an attachment still holds its writes in the default mode, so the CV
request becomes one card, *Add application (résumé: 1) → Move application to Offer*, and
one press of Confirm, with no further model call.

### 5. Manual, Balanced and Auto

The composer offers a mode, chosen per message and remembered in the browser:

| Mode | A write waits for Confirm when… |
| --- | --- |
| **Manual** | always. You approve every change the assistant proposes. |
| **Balanced** (default) | it is consequential (`confirmTools()`), the turn was a question, or the turn carried an attachment. These are the rules of ADR 0049. |
| **Auto** | it is consequential, or the turn was a question. |

Auto drops only the attachment rule. Consequential tools (deleting, archiving, hiring,
rejecting, granting access, notifying people) wait in every mode, because they cannot be
undone and an injected document is exactly what would ask for them. A write on a
question turn also waits in every mode, because it is the signature of a model steered by
what it just read. The mode is the person's own consent about their own requests, so it
travels with the request and is validated as an enum. It grants nothing: every tool is
still permission-checked.

**What Auto accepts.** With an attachment in Auto, a document written to steer the model
could cause a non-consequential write the user is allowed to make: an edit to a
candidate, a posting or a record. Such a write is logged "via assistant" and shown in the
trace. The mode switch says so, and points to Balanced for files nobody vouches for.

**A plan that lapses mid-turn** (its cache entry gone before the next write would join
it) refuses that write rather than starting a second plan, so a later step can never be
confirmed without the step it depends on.

### 6. Understanding requests

- Greetings and politeness are stripped before the instruction test ("hey", "hi",
  "please", "pls", "kindly", "po"; Filipino "paki-"). "can / could / would / will you"
  followed by a verb that changes something is an instruction, not a question.
- Verbs added: put, attach, upload, store, save, transfer, promote, register, onboard,
  place, link, edit, fix, correct, extend, book, enter, insert, import, fill, reassign.
- **A request to read is a question**, whatever its punctuation: "show me…", "can you
  check…", "give me…", "draft an announcement". A write the model proposes on one waits
  for Confirm in every mode, as ADR 0049 requires. (Found in review: counting read
  verbs as instructions would have let a model steered by what it just read write
  without a Confirm in the default mode.)
- **History carries what was done.** Each replayed assistant turn ends with a compact
  line naming the steps it took ("[Steps: Added … · Moved … to Offer]"), so a follow-up
  knows what "him" or "that posting" refers to.
- **Scope.** Beyond the workspace, the assistant may help with work-related writing and
  common knowledge: drafting a job description, an announcement or an email, or
  explaining an HR concept. It does this without tools. It declines anything harmful or
  unrelated to work.

### 7. Robust to the model's own failures

- An empty response, or a `MALFORMED_FUNCTION_CALL` finish, is retried once with the same
  contents. A `SAFETY` finish is answered with a polite refusal rather than an error.
- **Temperature is configurable.** `GEMINI_TEMPERATURE` defaults to the model's own
  default, except on `gemini-2*`, where 0.2 is kept. **`GEMINI_THINKING_LEVEL`** is
  optional (`minimal` / `low` / `medium` / `high`) and sent only when set.
- The model's turn is still echoed back unchanged, thought signatures included, as
  Gemini 3 function calling requires.

### 8. Tokens are counted

Each Gemini response's `usageMetadata` is summed per turn. The total is stored on the
assistant message as `usage`: requests, prompt, output, cached and thinking tokens. The
trace's footer shows *"3 model calls · 9.8k tokens"*, so cost is visible where it is
spent.

## Consequences

- **Multi-step requests complete.** The CV request is one card and one Confirm. "Add
  Ana to Cybersecurity Analyst and schedule an interview Friday 2pm" runs in one turn on
  an instruction without a file.
- **An action now usually costs two requests instead of one:** the call, then the reply.
  Each request is a sixth to a tenth of the old one, so a turn costs less overall. When
  the first request has to load a module, a turn may take a third.
- **The prompt no longer contains every module's guidance**, so a capability nobody
  routed is one `load_tools` call away rather than present. Tests that read the
  instruction for a module's guidance send a message that routes to it.
- **Auto mode is a real trade-off**, documented on the switch.
- **Chat uploads now persist** for the life of the conversation, on a private disk.
- **Unchanged:** the provider (Gemini), the permission model, the fence, argument
  cleaning, the reply guard and the rate limits.

## Alternatives considered

- **Keeping the one-request cost saver for writes.** It cannot tell "approve Maria's
  leave" from the first half of "add her and move her to Offer". Routing makes the
  follow-up cheap, so correctness wins.
- **Routing by keywords alone.** It is free, but a miss would leave the model without
  the tool and leave the user with the same failure as before. `load_tools` makes
  routing recoverable.
- **Letting the model call only tools that were shown.** It is stricter in appearance
  only. The security boundary is permission, not visibility, and refusing a permitted
  tool would just make the assistant fail more.
- **Server-side OCR (Tesseract, a PDF text parser).** It would be a new system
  dependency, and worse than the model's own document reading on scans and layouts.
  Gemini already receives the file.
- **Two modes only.** "Manual" and "Auto" were asked for. Balanced is the existing,
  reviewed rule set and stays the default, so nobody's behaviour changes without
  choosing it.
