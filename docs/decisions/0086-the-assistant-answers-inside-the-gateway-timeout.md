# 0086 — The assistant answers inside the gateway's timeout, and reads documents fully

- **Status:** Accepted
- **Date:** 2026-10-14
- **Related:**
  - [0068 — The assistant finishes the job](./0068-the-assistant-finishes-the-job.md)
    (the agent loop, plans and filed attachments this builds on);
  - [0061 — One container image](./0061-one-container-image-with-supabase-for-data-and-files.md)
    (the App Platform deployment whose edge sets the limit);
  - module doc: [Assistant](../modules/assistant.md).

## Context

In production, assistant turns sometimes ended in a bare **504**, and now and then in
"The assistant request failed (200)". The 504s came from turns with several model
requests. A CV turn takes three: find the posting, add the application, then reply.
Every request re-sends the whole conversation, the CV included. Nothing limited how
long a turn could take: each request could wait 60 s, a 503 was retried up to three
times, and an empty answer was retried once more. DigitalOcean App Platform's edge cuts
a request off at about 100 s, so a slow turn hit that limit, and the browser got the
edge's page while PHP carried on. The work could still finish, but the user never saw
it.

The same CV turn showed a second problem. "Extract the text, analyse the applicant's
information and add it to **my** Software Engineer recruitment" made the retriever
treat the turn as about the asker. "My" is a self marker, so it read the asker's whole
employee record (leave, attendance, appraisals, training, awards, events) and put it in
all three requests. That made each request bigger and slower, and gave the model the
wrong person to look at. Separately, the model filed only what the user listed, so a
plain "add this CV" stored a name and little else.

## Decision

**One time budget per turn.** `Assistant::handle()` gives the `GeminiClient` a deadline
of `GEMINI_TURN_SECONDS` (default 75 s, floor 20) through `until()`, and lifts it when
the turn ends. Under a deadline:

- each request waits at most what is left (and at most 60 s; 10 s to connect);
- a request is not started with under 5 s left;
- a 503 or empty-answer retry happens only while the budget still leaves room for it;
- a connection failure or timeout becomes `GeminiException(504)`, which `isBusy()`
  treats as retryable alongside 429 and 503 (`isTimeout()` names it).

The loop starts no further step with under 12 s left. A step that times out **after**
work was done (results, or a held plan) ends the loop, and the reply is composed from
that work as it already is when the model stops without writing. Only a turn that has
done nothing fails, as a retryable notice: "The AI took too long to answer, so I
stopped before changing anything." Changes made before the timeout are never hidden
behind a failure.

**A document turn is about the document.** `Retriever::retrieve()` and
`SubjectResolver::match()` take `$document`. On a turn with files, only a name the user
typed picks a subject; "my", "I" and pronouns pick nobody. "Put him in Offer" with a CV
attached means the person in the CV, not whoever the conversation last mentioned.

**A document is read fully by default.** The instruction says to read an attached
document in full whatever the wording, and to carry every detail it states into the
tool call. For a CV that means contact details, location, headline, links, years of
experience (worked out from dated roles when not stated), and a recruiter summary in
`notes` (skills, education, recent roles, achievements). The user's turn says the
documents are to be "read in full and used for what I asked". Recruitment's guidance
and the `notes` parameter say the same. The rule against inventing details stays.

**The browser names gateway failures.** `failureText()` turns a gateway's own
502/503/504/524 page, and a 2xx response whose body is not JSON, into a plain "try
again" message instead of a status code.

**Default model: `gemini-3.5-flash` with `GEMINI_THINKING_LEVEL=low`.**
`gemini-3.5-flash-lite` stays an option for cost.

## Consequences

- A turn that would have run past the edge now ends inside it with words: either what
  was done, or a retryable "took too long".
- A turn that runs out of time may stop before the model has queued every step it
  meant to. The reply says what was done or held, and the user asks for the rest.
- Document turns no longer carry the asker's record, so CV turns are smaller.
- Flash costs about five times as much per token as Flash-Lite (about $1.50 vs $0.30
  per million input tokens, and $9.00 vs $2.50 per million output). Low thinking keeps
  the thinking tokens, billed as output, small. A three-request CV turn of about 38k
  tokens costs roughly $0.06 on Flash and $0.01 on Flash-Lite, before implicit caching.
- The "(200)" case could not be reproduced locally. The browser now names it, but its
  cause is not known.
- `generate()` keeps its signature (many test doubles override it); the deadline is
  set beside it with `until()`.
