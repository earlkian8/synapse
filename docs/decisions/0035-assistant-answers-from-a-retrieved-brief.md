# 0035 — The assistant answers from a retrieved brief; tools are for doing

- **Status:** Accepted
- **Date:** 2026-09-10
- **Related:**
  [0027 — Assistant employee retrieval & disclosure policy](./0027-assistant-employee-retrieval-and-disclosure-policy.md)
  (retrieval is a live query, not an index — restated and extended here),
  [0024 — Agentic recruitment & permission-scoped tools](./0024-agentic-recruitment-and-permission-scoped-tools.md)
  (the tool surface this sits beside),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md) (the isolation retrieval must not escape).

## Context

The assistant could act on the workspace, and it could look things up. It could not
really **answer**.

"Tell me about Maria — what does she do, how is she doing?" went through the same path
as every other turn: the model picked a tool, the tool returned result cards, and the
orchestrator composed the reply *from the cards* with a local template
(`Assistant::synthesize()`), because spending a second Gemini request on wording was
against the project's cost discipline. What came back was
`Maria Santos — Sales Manager · Sales & Marketing (probationary · active)`.

That is a search result, not an answer. Three separate things were wrong with it:

- **One tool call sees one module.** Attendance, leave and onboarding all hold part of
  "how is she doing", and nothing assembled them. The model would have had to guess to
  chain four calls, and the loop is bounded at six steps for good reason.
- **The card shape is a summary, not a record.** Cards carry a title, a subtitle and a
  few chips because they are drawn on screen. Answering from them means answering from
  a rendering.
- **The model never got to write.** The one thing a language model is genuinely for
  here — reading a record and saying what it means — was the step being skipped to save
  a request.

## Decision

**Retrieval runs before the model, not through it. The tools stay for actions.**

### 1. Read first, then ask

Every turn now starts with `Retrieval\Retriever`: it works out who the turn is about and
asks each module what it knows about that person, then puts the result in the prompt as
a **context brief**. The model answers from material it did not have to think to fetch.

Because the reading happens *before* the single generation call, a grounded answer still
costs **one Gemini request** — the same as the template did. The cost rule was never
"don't call the model twice"; it was *never spend a call on something derivable
locally*. A confirmation sentence is derivable. An answer composed from a record is the
thing the model is for.

### 2. Who the turn is about is resolved by querying, never by the model

`SubjectResolver` reduces the message to word tokens and matches them against real name
and employee-number columns. A token that matches no row resolves to nobody, so the
retriever cannot be talked into briefing on somebody who does not exist, and a name is
never *spelled* by the model. It handles the three cases that are most of what people
type: **themselves** ("how many leave days do I have"), **a follow-up** ("and how is his
attendance?" — the last few turns are re-read for the subject), and **more than one
match** ("Maria", where there are two — the brief becomes a question rather than a
guess).

### 3. Modules describe, as well as act

`Contracts\ContributesContext` is a second, smaller contract beside `AssistantModule`:
given a subject, return what this module knows. The five modules that already have a
tool surface implement it — employee record, attendance over 30 days, leave balances and
recent requests, onboarding progress, and hiring origin (or, for a candidate, their
whole application history). A sixth module is one class away; nothing else changes.

### 4. The disclosure policy governs retrieval exactly as it governs tools

This is the point ADR 0027 makes, moved earlier in the pipeline rather than weakened:

- **The deny-list is the same one.** `EmployeeDisclosure::WITHHELD` still never reaches
  the model — the brief is built from `EmployeeDisclosure::profile()`, so pay, statutory
  numbers, bank details, home address and date of birth are absent by construction, and
  the brief *says* they are absent so the model does not fill the gap.
- **Permission is per module, per slice.** A module contributes only what the asker
  could already see on the screens: attendance for anybody needs `attendance.view`, but
  a person's own attendance needs nothing, because `/attendance/me` needs nothing.
  Retrieval discloses what a screen would, and nothing more.
- **Tenancy is asserted, not assumed.** The retriever refuses to run when no
  organisation is bound, for the same reason the tools do.
- **Reading a named individual is still audited.** An answer composed from somebody's
  record is a read of it, so it writes the same `viewed` activity entry the tool does.
  Reading your own record does not.
- **Nothing is announced that was not disclosed.** When every module declines, there is
  no brief and no timeline entry — otherwise "read Maria's record" would tell somebody
  without directory permission that Maria exists.

### 5. Reads on a question get written up; actions do not

The cost saver stays for mutations: "Approved Maria's leave" is composed locally, for
one request. But when the turn was a **question** and the tools only **read**, the
result now goes back for the model to write up — once per turn. That is the path for
questions the brief could not anticipate ("who is still probationary in Support"), and
it is the difference between an answer and a row of chips.

### 6. The timeline says what was read

The retrieval appears as a step of its own kind (`read`), naming its sources —
*Read Camila Bartell's record · Employee record · Leave (2026) · Attendance (last 30
days) · Onboarding*. A generated answer is only as trustworthy as the ability to check
it against the record, so the chat says which records those were.

## Consequences

- "How is she doing?" is answered from her actual attendance, leave and onboarding, in
  prose, with the real figures — and still in one request.
- Answers cannot be stale, cannot span organisations, and cannot exceed the asker's
  permissions, because retrieval is the same live query the screens run. **There is
  still no index**, for every reason ADR 0027 gave.
- The prompt is longer on a turn that resolves a subject. That is a token cost on the
  same single request, not an extra one, and the brief is bounded — a handful of lines
  per module, 30 days of attendance, five recent requests.
- The resolver is deliberately crude about names: it matches whole tokens against name
  columns, so a misspelling finds nobody rather than the wrong person. Ambiguity is
  surfaced instead of resolved.
- A question about something no module contributes (a performance appraisal, pay) is
  answered with "that isn't available to me" rather than an approximation — which is
  the same promise ADR 0027 made about withheld fields, now extended to whole subjects
  the assistant has no module for.

## Alternatives considered

- **An embedding index over the workforce.** Rejected again, for ADR 0027's reasons
  (a second copy outside row-level tenancy, stale by construction, no idea who is
  asking) and one more: for "how many probationary hires in Support", a `count(*)` beats
  nearest-neighbour search. The *augmentation* half of RAG is what was missing here, not
  the vector half.
- **Letting the model chain four read tools per question.** It is what the loop already
  allowed, and it does not happen reliably; when it does it costs four dispatches and a
  second request anyway, and each tool still answers in cards.
- **Always sending reads back for the model to word.** Simpler rule, and it doubles the
  request cost of every lookup on a free tier that allows ~20 a day. The question test
  keeps it to the turns where prose is the point.
