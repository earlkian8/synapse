# 0095 — Nominations of one person for one award are one case

- **Status:** Accepted
- **Date:** 2026-10-18
- **Related:**
  - [0071 — Recognition: kudos, nominations, points and rewards](./0071-recognition-kudos-nominations-points-and-rewards.md)
    (the nominations this revises);
  - module doc: [Awards & Recognition](../modules/awards.md).

## Context

ADR 0071 lets any number of colleagues nominate the same person for the same award, and
allows one pending nomination per nominator. The review queue and the workflow both
treated each nomination as its own decision.

- **The queue stacked.** Two colleagues putting Ana forward for Employee of the Month
  showed as two cards, each with its own *Approve*. Nothing said they were about the
  same thing.
- **The award could be given twice.** Approving one card gave the award and its points.
  The other card stayed pending, and approving it later gave the same award again, with
  the points again. The assistant's `review_nomination` had the same gap: it decided
  "the oldest first" and left the rest waiting.
- **The counts were off.** "Nominations (2)" on every Awards page meant one decision.

## Decision

### A case is a person and an award

The pending nominations of one person for one award form a **case**.
`App\Queries\NominationCases` reads them as such: the pending cases (oldest first), the
decided ones (nominations decided together share their reviewer and moment), the
number waiting, and the nominators' names ("Ana Cruz and Ben Reyes").

### Deciding one decides the case

`NominationWorkflow::approve()` and `reject()` take any nomination in a case and decide
all of it:

- The case is read again under a row lock inside the transaction. A second reviewer
  acting at the same moment waits, then finds it decided.
- Approving gives the award **once** through `AwardWorkflow::give()`, and every
  nomination in the case is approved and linked to that award. The citation starts as
  the words of the nomination decided through.
- Turning it down rejects every nomination in the case with the same note.
- Every nominator is told. The activity log says how many colleagues put the person
  forward.
- A reviewer who nominated anyone in the case, or who is the nominee, decides none of
  it.

Nominating someone already waiting for that award is still allowed. It adds a voice to
the case. HR is told "Ana Santos nominated again for Employee of the Month", with "2
colleagues have put them forward now", rather than about a new nomination. A different
award is a different case.

### What shows

- **Nominations** (`/awards/nominations`) shows one card a case: the nominee and award,
  "Put forward by N colleagues", and each nominator's words with their name and date.
  The approve dialog can start the citation from any nominator's words. A decided case
  is one row, "by Ana Cruz and Ben Reyes". The page's prop is `cases`.
- The waiting count on every Awards page, and in the assistant's overview, counts cases.
- The assistant's `find_nominations` returns a card a case, and `review_nomination`
  decides the whole case and says how many nominators were told.
- A nominator's own **My nominations** (web and app) is unchanged. It lists their own,
  each showing how its case was decided.

## Consequences

- An award can no longer be given twice from one round of nominations.
- A withdrawn nomination leaves the rest of its case waiting. Withdrawing is still the
  nominator's own.
- Nothing about the data changes. A case is derived from the rows, so there is no
  migration. Nominations decided one by one before this change keep a row each among
  the decisions, because they don't share a review.
