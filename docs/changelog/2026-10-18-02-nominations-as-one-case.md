# Nominations of one person for one award are one case

When two colleagues nominated the same person for the same award, HR's queue showed two
cards with two *Approve* buttons. Approving both gave the award twice, with its points
twice. They are now one case: one card, one decision, one award, and every nominator is
told. See
[ADR 0095](../decisions/0095-nominations-of-one-person-for-one-award-are-one-case.md).

## Highlights

- **One card per person and award** on Awards → Nominations. It says "Put forward by
  2 colleagues" and shows each nominator's words with their name and date.
- **Approved once.** Approving gives the award and its points once, and approves every
  nomination in the case. The citation can start from any nominator's words.
- **Turned down together.** Every nomination in the case is turned down with the same
  note, and each nominator reads it.
- **Counts are cases.** The *Nominations (n)* badge on every Awards page now counts
  decisions to make, not rows.
- **"Nominated again."** HR's notice for a second nominator says so, with how many
  colleagues have put the person forward, instead of announcing a new nomination.

## Backend

- `App\Queries\NominationCases` reads pending and decided cases, the number waiting,
  and the nominators' names.
- `NominationWorkflow::approve()` and `reject()` decide the whole case. The case is read
  again under a row lock, so two reviewers can't decide it twice. A reviewer who
  nominated anyone in the case can't decide it.
- `RecognitionFeed::nominationCase()`. The queue's prop is now `cases`.
- The assistant: `find_nominations` returns one card per case ("Nominated by Ana Cruz
  and Ben Reyes"), `review_nomination` decides the whole case, and the overview counts
  cases.

## Frontend

- `awards/nominations.tsx`: case cards, the approve dialog's "Start from any
  nominator's words", and the decided rows naming every nominator.
  `NominationCase` was added to the recognition types.

## Notes

- Tests: three in `NominationTest`, one in `RecognitionScreensTest` and one in
  `RecognitionAssistantTest`.
- No migration: a case is derived from the existing rows.
