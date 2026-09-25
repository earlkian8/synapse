# The assistant reads the file before it answers

Ask the assistant "tell me about Maria — what does she do, how is she doing?" and until
now you got a search result:

> Maria Santos — Sales Manager · Sales & Marketing (probationary · active)

That was the honest output of how a turn worked. The model picked one tool, the tool
returned display cards, and the reply was assembled from those cards by a local template
— because spending a second AI request on wording is against this project's cost
discipline, and the free tier allows about twenty requests a day.

The fix is not to spend more requests. It is to **read the record before asking the
model anything**, so the one request it does spend is the one that writes the answer.

## Highlights

- **Retrieval runs first.** Every turn now resolves who it is about and assembles a
  **context brief** across the modules — the employee record, 30 days of attendance,
  this year's leave, onboarding progress, and how they were hired — then hands it to the
  model as ground truth. Still one request per turn.
- **The answer is prose, with the real numbers in it.** "Worked 22 of 22 scheduled days,
  late once for 35 minutes, 8h 50m of overtime, 5 of 10 onboarding tasks done and all
  five outstanding ones overdue" — not a row of chips.
- **Follow-ups keep their subject.** "And how is his attendance?" re-reads the last few
  turns for the person the conversation is about.
- **"How many leave days do I have?" works.** A first-person turn resolves the asker's
  own record, and needs no directory permission — the same rule
  `get_my_employee_record` already followed.
- **Two Marias get a question, not a guess.** An ambiguous name returns everyone it
  could have meant, and the assistant asks which.
- **The timeline says what it read.** A retrieval appears as its own kind of step,
  naming its sources: *Read Camila Bartell's record · Employee record · Leave (2026) ·
  Attendance (last 30 days) · Onboarding*. A generated answer is only as trustworthy as
  the ability to check it.
- **Function calling is unchanged, and still what does the work.** Filing leave, hiring,
  archiving, bulk adds from a CV — all exactly as before.

## Backend

- **New** `Services\Assistant\Retrieval\` — `Retriever` (fan-out across modules),
  `SubjectResolver` (who the turn is about), `ContextBrief` and `ContextSection` (what
  goes in the prompt, and what the timeline names), `RetrievedSubject`.
- **New** `Services\Assistant\Contracts\ContributesContext` — a second, smaller contract
  beside `AssistantModule`: *describe* a subject rather than act on one. All five modules
  with a tool surface implement it; a sixth is one class away.
- `Assistant::handle()` retrieves before the loop, appends the brief to the system
  instruction, and records the retrieval step. The system instruction now separates
  **answering** from **acting**, and tells the model to answer from the brief rather than
  call a tool for anything it already contains.
- **Reads on a question turn are written up by the model**, once per turn; mutations are
  still narrated locally for free. A confirmation is derivable; an answer is not.
- `SubjectResolver` matches whole word tokens against real name and employee-number
  columns, so a name that matches no row resolves to nobody and a name is never *spelled*
  by the model. "Tell me about Maria" is about Maria — an explicit name outranks the
  first-person pronoun in "tell me".

## Security

The disclosure policy governs retrieval exactly as it governs tools — moved earlier in
the pipeline, not weakened:

- The brief is built from `EmployeeDisclosure::profile()`, so pay, government ID
  numbers, bank details, home address and date of birth are absent by construction, and
  it says they are absent rather than leaving the model to fill the gap.
- Each module contributes only what the asker could already see on the screens.
  Attendance for anybody needs `attendance.view`; their own needs nothing, because
  `/attendance/me` needs nothing.
- Retrieval refuses to run when no organisation is bound, like the tools.
- Reading a named individual writes the same `viewed` activity entry the tool does.
  Reading your own record does not.
- When every module declines, there is no brief and no timeline entry — otherwise
  "read Maria's record" would tell somebody without directory permission that Maria
  exists.

## Frontend

- `AgentStep` gains an optional `kind`; a `read` step renders with its own mark in the
  activity timeline. No migration — steps are a JSON column, and older turns simply lack
  the field.

## Notes

- Pest: **747 tests, 747 passed** — 21 new ones covering resolution (full name in prose,
  a surname, an employee number, ambiguity, a follow-up, first person), permission
  scoping per module, self-service, the audit entry, the withheld-field deny-list, tenant
  isolation, refusal with no workspace bound, and — against a stub client — that the
  brief reaches the prompt and that a read on a question turn is narrated while an action
  is not.
- The brief itself was smoke-tested against the seeded demo tenant, and the timeline
  rendering was checked in a browser. **A live end-to-end Gemini turn could not be run:
  the API key's free-tier daily quota (20 requests) was already spent.** The answer shown
  in the browser pass was written by hand into a seeded turn to exercise the rendering —
  it is not model output, and the conversation was removed afterwards.
- No schema, route or permission change.
- See [ADR 0035](../decisions/0035-assistant-answers-from-a-retrieved-brief.md).
