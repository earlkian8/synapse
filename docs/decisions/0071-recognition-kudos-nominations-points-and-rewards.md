# 0071 — Recognition: kudos, nominations, points and rewards

- **Status:** Accepted
- **Date:** 2026-10-10
- **Related:**
  - [0014 — Awards & recognition](./0014-awards-and-recognition.md) (the module this extends);
  - [0050 — Assistant: training, awards and events](./0050-assistant-training-awards-and-events.md)
    (Confirm for tools that tell someone else; the awards tools this widens);
  - [0070 — Events answered by invitees](./0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md)
    (built alongside; the same "one way in" navigation);
  - module doc: [Awards & Recognition](../modules/awards.md).

## Context

Recognition was top-down only. HR gave awards; nobody else could take part. The page
called "Nominations" was an AI-ranked shortlist with citation drafting — decision
support, not nominations. There was no way for a colleague to put someone forward, no
points or rewards, and no peer-to-peer thanks. The recipient of an award was not even
told.

## Decision

### Taking part is its own permission

**`awards.participate`** — "Give kudos, nominate colleagues & redeem rewards
(self-service)" — goes to the built-in Staff, Department Head and HR Manager roles,
back-filled like `events.respond`. Running the programme stays `awards.manage`.

### Nominate, then approve

`award_nominations` records the nominee, the award type, who nominated (user and
employee), the reason, the status (pending, approved, rejected, withdrawn), and the
review. `award_types.accepts_nominations` (default on) decides which types colleagues
may nominate for.

`NominationWorkflow` enforces: an active colleague, never oneself; an active type open
to nominations; a reason of 20–1,000 characters (it becomes the citation); one pending
nomination per nominator, nominee and type; withdrawal by the nominator while pending;
and **no reviewer who is part of it** — neither the nominator nor the nominee.
Approving gives the award through `AwardWorkflow::give()`, with an editable citation
(the existing AI draft is offered) and date; rejecting takes an optional note. HR hears
of a new nomination; the nominator hears the decision.

The AI-ranked board keeps its job and is renamed **AI shortlist**: the second view of
Nominations, at `/awards/shortlist`.

### Points are a ledger

`point_transactions` holds signed lines — award, kudos, redemption, refund, adjustment
— each with an optional subject (a morph to the award, kudos or redemption), a note and
who made it. **The balance is the sum**; nothing is edited in place. `PointsLedger::settle()`
moves a subject's net to a target by posting the difference, so revising an award's type
posts the change and removing it reverses it. A reversal may take a balance below zero;
redeeming waits until it covers the cost.

- `award_types.points` (0–10,000, default 0) is credited when the award is given, and
  the recipient is now notified ("You've been recognised: … (+N points)").
- Kudos credit `organizations.kudos_points` (default 10; 0 turns it off) for at most
  `organizations.kudos_monthly_limit` per sender per calendar month on the
  organisation's clock (default 5). Past the limit, kudos still go out without points,
  and the sender sees how many are left before sending.
- HR can post an **adjustment** with a required reason the person sees — never to their
  own balance.

### Rewards are redeemed under a lock

`rewards` (name, description, cost, stock or unlimited, active, soft deletes) and
`reward_redemptions` (cost as charged, status pending, fulfilled, declined or cancelled,
notes, who handled it). Redeeming locks the employee row and the reward row in one
transaction, checks the balance and stock, debits the cost and decrements the stock, so
two taps cannot spend the same points twice. Cancelling (the employee, while pending) or
declining (HR) refunds and restocks; fulfilling closes it. HR cannot handle their own
request.

### Kudos and the wall

`kudos` (from, to, a message of up to 500 characters, the points credited, soft
deletes); never to oneself, and the recipient is notified. HR can take kudos down,
reversing their points. The **wall** shows kudos and awards from the last 90 days,
newest first — an award at the time it was entered, or at noon of its date when it was
backdated, so a late entry doesn't jump to the top.

### One way in: a section of the module, not a second sidebar entry

The first cut added "Recognition" to the sidebar's Main group, next to the existing
"Awards & Recognition" under Workforce — two near-identical names for one subject.
Instead, **Awards & Recognition** is one entry, shown to whoever holds `awards.view` *or*
`awards.participate`, and its pages share one URL prefix: the participant routes moved
from `/recognition/*` to `/awards/wall`, `/awards/points` and `/awards/my-nominations`.
The header's section control (`ModuleNav`) has two groups — **Wall · My points · My
nominations**, then **Awards · Nominations · Rewards** — each item shown to whoever may
open it, with the count of nominations waiting on Nominations. Someone who only takes
part lands on the wall from `/awards`.

## Consequences

- Recognition is public to the workspace: the wall shows every kudos and award.
- Points have one source of truth, auditable line by line; balances are computed, not
  stored, so a top-10 list sums the ledger.
- Points are a company-run currency, not money. Nothing here is payroll; a reward is
  handed over by HR outside SYNAPSE.
- The mobile app has the wall, kudos, nominations, points and rewards through
  `/api/recognition`, `/api/kudos`, `/api/nominations`, `/api/points` and
  `/api/rewards`, with the same workflows. The assistant gains `give_kudos`,
  `nominate_colleague` and `get_my_points` for participants, and `find_nominations`,
  `review_nomination`, `find_redemptions` and `handle_redemption` for HR; the ones that
  tell someone else wait for Confirm.
