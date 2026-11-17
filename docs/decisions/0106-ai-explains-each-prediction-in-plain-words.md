# 0106 — AI explains each prediction in plain words

- **Status:** Accepted
- **Date:** 2026-11-17
- **Related:**
  - [0098 — The predictive screens say why](./0098-the-predictive-screens-say-why.md)
    (the exact reasons this reads);
  - [0099 — Each breakdown is a decision brief](./0099-each-breakdown-is-a-decision-brief.md)
    (the dialog it sits in, and the record beside the score);
  - [0107 — A model that did not pass can still be chosen](./0107-a-model-that-did-not-pass-can-still-be-chosen.md)
    (the decision the check's reading informs);
  - module docs: [Attrition Risk](../modules/attrition-risk.md),
    [Promotion Readiness](../modules/promotion-readiness.md),
    [Performance Forecast](../modules/performance-forecast.md),
    [Model graduation](../modules/model-graduation.md).

## Context

Since ADR 0098 every score carries its exact reasons, and since ADR 0099 each person's
breakdown is a decision brief with a rule-based verdict and next steps. HR still has to
put it together: a ledger of points, the survey's leave rates, behavioural indicators,
what-if scores and six months of record are all there, but nothing says in a sentence
*why the system decided this* about this person. The same is true of a model check:
"ahead in 64 % of re-checks, 90 % needed" is exact and hard to act on.

The project already uses Gemini for decision support elsewhere (applicants, reports,
training, performance), always on request, one call, strict JSON, saved, degrading to a
retryable "unavailable" answer.

## Decision

**An on-request plain-language reading, in the Summary tab and on each model check.**
Under the verdict, *In plain words* offers **Explain this score** (*this forecast*). The
reading has a headline, a summary, the reasons, how the record reads beside the score,
what to confirm before acting, and what to do. In the graduation modal, each result card offers
**Explain this result**: what each part of the check showed and what switching or
keeping the current model would mean.

**The model writes sentences, not facts.** `PredictionDigest` tags each reason (`[R1]`…,
`[C1]`… for a check) and keeps its label, effect, points — or whether a check was met —
on the server. The answer's list of reasons is built from that list, in its order; the
model only supplies the sentence for each tag it was given. A tag it invents is
dropped, and a "met" it claims is overwritten by the stored comparison. The prompt also
bans jargon (Shapley, AUC…), invented numbers, and advice to discipline, dismiss or
change pay on the strength of a prediction.

**Written for what HR needs to know, not how the system works.** The prompt forbids
explaining how the system was built: training, the survey and its size, statistical
ideas like "patterns, not causes". Leave rates are given as "among people with a similar
record". The reading's last list is **Before you act** (*Before you decide* for a check):
things to confirm about this person, such as an input not on record, an old assessment,
or a question to ask them. The page carries one standing disclaimer in place of AI-written
caveats: "Written by AI · *time*. AI can make mistakes. Check important details before
acting."

**Privacy by construction, not instruction.**
- The person is never named: no name, employee number, photo or manager.
- Pay never leaves, matching the assistant's rule (`PredictionWording::WITHHELD_INPUTS`):
  not its amount, band, points or direction, nor the pay-band scenario, the "all
  together" scenario or effort–reward imbalance. Its reason is listed with its points and
  a fixed note that it is never sent.
- Leave goes as days, never by type: a type can be a health matter.
- The record comes through `PredictionContext` with the **viewer's** permissions; a block
  they may not see is named as unknown, so its absence is never read as "none".
- A model check sends aggregates only.
- Every typed value is cleaned to one bounded line (`UntrustedText`), and the prompt
  treats the digest as untrusted data.

**Saved, and shown only to who may see what it read.** The reading is stored in an
`interpretation` column on the score (and on `local_models` for a check), with `needs`:
the permissions behind the record it read (`PredictionContext::permissionsFor`). The
score resources show it only to viewers holding all of them, without `needs`. Anyone else
sees the button and can have one written from what they can see.

**Cost and audit.** One call per request, on request only. The endpoints
(`POST analytics/<surface>/scores/{score}/interpretation`,
`…/graduation/{localModel}/interpretation`) need the surface's view permission and the
new `interpretations` rate limit (10 a minute, 150 a day, per person). Each reading
written is activity-logged ("Asked AI to explain …"), since it sends part of a record to
the AI service. A missing key, a quota or an unreadable answer comes back as an
"unavailable" reading, retryable where waiting helps.

**Shared plumbing.** `App\Support\Ai\GeminiReply` reads a one-shot JSON answer and words
failures. The five older insight classes keep their private copies; moving them is a
separate clean-up.

## Consequences

- HR gets a sentence-level answer to "why?" next to the numbers it rests on, and the
  numbers in it cannot drift from the page's.
- Readings go stale as the record changes. Each shows when it was written, and **Write
  again** refreshes it.
- Pay, often the largest attrition reason, is listed but not explained. That is the
  price of never sending pay; the Reasons tab still explains it with the survey data.
- Without `GEMINI_API_KEY` the button says AI explanations aren't configured; nothing
  else changes.
- The Profile link in the breakdown dialog's header was removed at the same time, with
  `employment.profile_url` from the context endpoint.
