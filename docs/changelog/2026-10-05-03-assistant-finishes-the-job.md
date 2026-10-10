# The assistant finishes the job: it keeps going until the request is done, files what you attach, and asks first only when you want it to

A recruiter attached a CV and wrote *"put this person in cybersecurity analyst as an
offer"*. The assistant looked the posting up and stopped there. Nobody was added, and
the CV was dropped. Two things caused it: a cost-saving rule ended every turn after its
first successful step, and chat had no way to put a file on a record. The cost rule
existed because every request carried all 276 tools, about 40k tokens. Now the
assistant runs a real agent loop over a few routed tools per request, confirms a whole
plan at once, files attachments onto records, and offers a Manual / Balanced / Auto
mode. See [ADR 0068](../decisions/0068-the-assistant-finishes-the-job.md) and the
[module doc](../modules/assistant.md).

## Highlights

- **Multi-step requests complete.** The model is called again after each tool result
  until it writes its answer: look up, act, act again. The limits are 8 round trips,
  15 calls and 5 changes per message. A call already made in the turn is never run
  twice.
- **The CV request becomes one card.** *1. Add application (résumé: attachment 1) ·
  2. Move application to Offer.* One Confirm runs both, in order, and stops at the
  first step that fails. The reply names anything that never ran.
- **Attachments are read and filed.** Gemini reads PDFs and images itself, scans
  included (this is the OCR step), and fills the candidate's details from the CV. The
  file is kept privately for the conversation and copied onto the record as the
  résumé or a supporting document. An employee's 201 file can take a contract or an ID
  the same way, always after Confirm. A later "add the person in the CV" re-reads the
  file.
- **Manual, Balanced and Auto.** A switch in the composer, remembered per browser.
  Manual asks before every change. Balanced (the default) keeps the reviewed rules.
  Auto runs ordinary changes at once, files included, and the switch says what that
  risks. Deleting, hiring, granting access, notifying people, and any change proposed
  on a question still ask first in every mode.
- **Fewer tokens per request.** Each request carries only the tools of the modules the
  message is about, about 10–25 KB instead of about 155 KB. The model loads any other
  module with `load_tools` when it needs one. Each reply shows what it cost
  ("2 model calls · 9.8k tokens").
- **It understands how people write.** "hey can you put…", "please approve…" and
  "Paki-approve po…" are instructions. "Can you check…", "show me…" and "give me…" are
  questions, so a write the model proposes on one still waits.

## Backend

- `Assistant::handle()` is rewritten as a loop. `TurnState` carries the mode, the
  held plan and a repeat guard. `executePlan()` replaces single-call execution, and
  `execute()` is now a plan of one. The new system instruction covers planning,
  loading tools, filing attachments, and work-related writing without tools.
- `Routing\ToolRouter` routes locally from per-module hints and tool-name words, the
  last turn's modules, attachments and the brief, and builds the catalogue line for
  each unloaded module. Permission is still the allow-list.
- `Security\PendingActions` holds plans (`hold(user, conversation, steps)`, `extend()`),
  and still reads single calls held before this change. A plan that lapses mid-turn
  refuses the next step instead of starting a second plan.
- `Attachments\ConversationAttachments` and `StoredAttachment` store uploads on the new
  private `assistant` disk, number them, resolve a number or name only within the bound
  conversation of the signed-in user, re-send a referred-to file, and purge files when
  a conversation is deleted or cleared.
- `RequestIntent` replaces `Assistant::isQuestion()`.
- Recruitment: `resume_attachment` and `document_attachments` on `add_applicant`,
  `add_application` and `update_applicant` (filed in a transaction, under the form's
  own type and size rules), and `stage` on `add_application`.
- Employee records: `add_employee_document` (always confirmed). The upload screen now
  uses the new `Support\Employees\EmployeeDocuments`.
- `ApplicantDocumentStore::problemWith()`, `resumeFrom()` and `documentFrom()`.
- `GeminiClient`: `GEMINI_TEMPERATURE` (unset means the model's own default, or 0.2 on
  `gemini-2*`) and `GEMINI_THINKING_LEVEL`. It retries once on an empty answer or a
  `MALFORMED_FUNCTION_CALL`, and a blocked answer gets a polite refusal.
- Migration `add_usage_to_assistant_messages`: a turn's requests and prompt, output,
  cached and thinking tokens. `attachments` rows now hold `{name, mime, size, path}`;
  rows of bare names still read.
- `POST /assistant` and `/regenerate` take `mode`. Regenerate now re-reads the
  message's files.

## Frontend

- `components/mode-menu.tsx`: Manual / Balanced / Auto with what each means, in the
  composer, stored as `synapse.assistant.mode`.
- The confirm card numbers a plan's steps with their exact arguments, and says that
  steps run in order and stop at the first failure.
- The reply footer shows the turn's model calls and tokens, with a breakdown on hover.

## Notes

- **Tests.** There are new tests for the loop, routing, attachments, plans, modes,
  filing, request intent and the Gemini client (`fakeAssistantModel()` in
  `tests/Pest.php`).
- **Updated tests.** Pre-existing tests whose rule changed on purpose were updated:
  3 → 5 writes, de-duplicated repeat calls, the model writing up after every tool step,
  and `hold()` taking a plan.
- **Hermetic tests.** `AssistantEndpointSecurityTest` and `ApplicantInsightsTest`
  depended on a real `GEMINI_API_KEY` in `.env`, and the endpoint test's rate-limit case
  would have made 12 real calls. Both now use a stub or a fake key with `Http::fake`.
- **Suite and checks.** 1,561 tests pass. Pint, tsc, ESLint, Prettier and the build
  are green. The panel was checked in headless Chromium at 1440 and 390 px wide.
- **Not run.** No real Gemini request was made, and the Docker image was not built or
  run.
- **Review.** A final review found the read-verb regression, the attachment-numbering
  bug, the lapsed-plan split and over-eager re-reading of files. All are fixed, each
  with a test. The deferred minor items are listed in the commit message.
