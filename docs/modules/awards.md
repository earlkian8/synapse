# Awards & Recognition

Celebrate great work: give employees **recognitions** drawn from a typed catalogue,
and see them in a chronological **recognition feed**. Award types are configured in
Company Setup; recognitions are given in the module. Data model is ERD §9 (with the
§2 `award_types` config); everything is tenant-scoped (ADR 0005). See
[ADR 0014](../decisions/0014-awards-and-recognition.md), and
[ADR 0071](../decisions/0071-recognition-kudos-nominations-points-and-rewards.md) for
kudos, nominations, points and rewards — recognition everybody takes part in.

> Status: **Active** · Route prefix: `/awards` · Config: `/setup/award-types`
> Sidebar: Workforce → Awards & Recognition (shown with `awards.view` **or**
> `awards.participate`; someone with only the latter lands on `/awards/wall`);
> Company Setup → Award Types (gated by `setup.award-types.view`)

## Surfaces

The module's pages share a section control in the header (`AwardsNav`, built on the
shared `ModuleNav`) in two groups, each item shown to whoever may open it: **Wall · My
points · My nominations** (`awards.participate`), then **Awards** (`awards.view`) ·
**Nominations** (with the count waiting) · **Rewards** (`awards.manage`).

**Taking part** (`awards.participate`):

- **`/awards/wall`** — the **recognition wall**: kudos and awards from the last 90
  days, newest first (an award at its entry time, or noon of its date when
  backdated), filterable to Kudos or Awards and shown 15 at a time. A composer sends
  kudos; beside it, the person's points, kudos with points left this month, and
  *Nominate a colleague*. HR can take kudos down.
- **`/awards/points`** — **My points**: tiles (points to spend, rewards in reach,
  requests waiting, kudos left), the reward catalogue with *Redeem*, the person's
  requests (cancel while pending) and the ledger lines behind the balance.
- **`/awards/my-nominations`** — the colleagues the person nominated and what was
  decided; withdraw while pending.

**Running it:**

- **`/awards`** — the **awards register**: stat tiles (recognitions all-time, this
  month, people recognised, active award types) and a table of awards (recipient, a
  colour-tinted award-type badge, reason, date awarded, who gave it). Filter by award
  type or search by employee. HR can **give recognition**, **export**, and **edit** or
  **remove** an award from its row menu.
- **`/awards/nominations`** (`awards.manage`) — **Nominations**, view *From
  colleagues*: the pending queue, oldest first, each approved (citation editable, AI
  draft offered, date) or turned down with a note; below, decisions from the last 90
  days. A nomination the reviewer is part of shows who decides instead of buttons.
- **`/awards/shortlist`** — Nominations, view **AI shortlist** (the "nomination
  board" before ADR 0071), in two levels. First, a table of award types (what each
  weighs, nominees, the front-runner and their score). Opening one (`?type=`) shows
  that award's **ranked shortlist**. Each row expands into the breakdown of its
  score, and the front-runner opens by default.
- **`/awards/rewards`** (`awards.manage`) — the **rewards desk**: requests to hand over
  or decline, the catalogue (new, edit, archive, restore), the ten highest balances
  with *Adjust points*, and the kudos settings (points per kudos, kudos with points
  per person a month).
- **Employee detail → Awards tab** — a read-only summary of an employee's
  recognitions (given from this module, not the employee record).

The register and the shortlist use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)).

## Configuration (`/setup/award-types`)

Company Setup → **Award Types** manages the catalogue: name, description, an accent
**colour**, the **points** it gives (0–10,000), whether it is **open to nominations**,
and an active flag. Full lifecycle: create / edit / archive (soft delete) /
restore / permanent delete; each row shows how many awards it has been given for. A
type that has been given out cannot be permanently deleted (archive instead); archived
types still render on the past awards that used them.

## Permissions

`awards.view` (the register), `awards.manage` (give / edit / remove recognitions,
review nominations, the AI shortlist, the rewards desk, adjust points, remove kudos),
`awards.participate` (the wall, kudos, nominating, one's own points and rewards);
`setup.award-types.view` / `setup.award-types.manage` (the configuration surface).
Built-in **HR Manager** gets all of them; **Department Head** `awards.view` and
`awards.participate`; **Staff** `awards.participate`. The granting user is recorded on
each award.

## Where the rules live

`App\Support\Awards\AwardWorkflow` is the one path that gives, revises or takes back
a recognition, for the screens and the assistant alike. It refuses, in words shown as
they are (`AwardException`):

- **an award type that is no longer given out** (inactive or archived). An award that
  already has such a type keeps it and can still be edited; only giving it anew, or
  switching an award to it, is refused;
- **a date in the future, by the organisation's calendar.** The form's own rule uses
  the organisation's today too. Before, a Manila morning award could be refused as
  "in the future" because UTC was still on yesterday.

The employee and award-type ids are validated against the current workspace
(`TenantRule`). Before, another organisation's id passed validation and was stored.

`AwardWorkflow` also keeps points in step through `PointsLedger::settle()` — giving
credits the type's points, changing the type posts the difference, removing reverses
it — and tells the recipient they were recognised.

Recognition's own rules live in `App\Support\Recognition` and refuse in words
(`RecognitionException`, with the field it concerns):

- **`PointsLedger`** — the append-only ledger (`point_transactions`); a balance is the
  sum. Adjustments need a reason, 1–100,000 points, never one's own.
- **`KudosWorkflow`** — never to oneself, to active colleagues, 1–500 characters;
  points while the sender has kudos with points left this month (organisation
  settings); the recipient is notified. Taking kudos down reverses their points.
- **`NominationWorkflow`** — an active colleague, not oneself; a type open to
  nominations; a reason of 20–1,000 characters; one pending per nominator, nominee and
  type; no reviewer who nominated or is nominated. Approval gives the award through
  `AwardWorkflow::give()`.
- **`RewardWorkflow`** — redeeming locks the employee and the reward, checks balance
  and stock, debits and decrements in one transaction; cancel and decline refund and
  restock; nobody handles their own request.

`App\Queries\RecognitionFeed` shapes the wall, the person's points and every
recognition payload for the web pages and the mobile API alike.

## The assistant

`App\Services\Assistant\Modules\AwardsModule` puts recognition in the chat
assistant ([ADR 0050](../decisions/0050-assistant-training-awards-and-events.md)).

- **Reads** (`awards.view`):
  - `find_awards` — by person, award type, and since a date;
  - `list_award_types` — the catalogue, active or retired, with how often each was
    given;
  - `awards_summary` — the feed's tiles, the latest awards and the most-given types.
- **The nomination board** (`get_award_nominees`) needs `awards.manage`, as the board
  does, because it ranks people against each other. It is `AwardNominator`'s own
  ranking, breakdown and repeat-winner flag included. `AwardNominator::for()` scores
  one award type without scoring the rest.
- **Writes** (`awards.manage`), all through `AwardWorkflow`:
  - `give_award` — the date defaults to the organisation's today;
  - `update_award` — an award is identified by its person, and by its type or date
    when they have several. It is never "the latest one" by guess;
  - `remove_award` always waits for the user's **Confirm**.
- **Taking part** (`awards.participate`): `give_kudos` and `nominate_colleague` —
  both tell someone else, so both wait for **Confirm** — and `get_my_points` (balance,
  kudos left, recent lines; HR may name someone else, nobody else may).
- **Review** (`awards.manage`): `find_nominations` and `find_redemptions` (pending by
  default, oldest first), and `review_nomination` (approve or reject) and
  `handle_redemption` (fulfil or decline), which wait for **Confirm**.
- **Retrieval:**
  - a question about a person carries their recognitions with the citations. One's
    **own** recognitions need no permission, since the mobile app already shows them
    to their owner;
  - a question about recognition that names nobody carries the feed and, for someone
    taking part, their own points. For `awards.manage`, it also carries the
    front-runner for each award type and how many nominations and reward requests
    wait.

### Award types in the assistant

`App\Services\Assistant\Modules\AwardTypesModule` keeps the catalogue
([ADR 0055](../decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md)).
Every write goes through **`App\Support\Setup\AwardTypeWorkflow`**, which the Award
Types screen uses too (`AwardTypeException` for the given-out delete guard).

- **Reads** (`setup.award-types.view`): `find_award_types` (archived on request) and
  `get_award_type` — how often a type was given, this year and in all, and when last.
  **Never to whom**: that is this module's awards capability, under `awards.view`.
- **Writes** (`setup.award-types.manage`): `create_award_type`, `update_award_type`
  (rename, describe, retire or reactivate), `restore_award_type`, and
  **`archive_award_type`, which waits for Confirm**.
- A name another type has in any case is refused. Permanent deletion stays on the screen.

## Out of scope (this cut)

Points that expire; budgets or caps per manager; reactions and comments on the wall;
paying rewards out through payroll (HR hands a reward over outside SYNAPSE).
