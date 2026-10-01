# Awards & Recognition

Celebrate great work: give employees **recognitions** drawn from a typed catalogue,
and see them in a chronological **recognition feed**. Award types are configured in
Company Setup; recognitions are given in the module. Data model is ERD §9 (with the
§2 `award_types` config); everything is tenant-scoped (ADR 0005). See
[ADR 0014](../decisions/0014-awards-and-recognition.md).

> Status: **Active** · Route prefix: `/awards` · Config: `/setup/award-types`
> Sidebar: Workforce → Awards & Recognition (gated by `awards.view`);
> Company Setup → Award Types (gated by `setup.award-types.view`)

## Surfaces

- **`/awards`** — the **recognition feed**: stat tiles (recognitions all-time, this
  month, people recognised, active award types) and a table of awards (recipient, a
  colour-tinted award-type badge, reason, date awarded, who gave it). Filter by award
  type or search by employee. HR can **give recognition**, **export**, and **edit** or
  **remove** an award from its row menu.
- **`/awards/nominations`** — the **nomination board**, in two levels. First, a table
  of award types (what each weighs, nominees, the front-runner and their score).
  Opening one (`?type=`) shows that award's **ranked shortlist**. Each row expands
  into the breakdown of its score, and the front-runner opens by default.
- **Employee detail → Awards tab** — a read-only summary of an employee's
  recognitions (given from this module, not the employee record).

Both pages use the shared Workforce table kit
([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)).

## Configuration (`/setup/award-types`)

Company Setup → **Award Types** manages the catalogue: name, description, an accent
**colour** and an active flag. Full lifecycle: create / edit / archive (soft delete) /
restore / permanent delete; each row shows how many awards it has been given for. A
type that has been given out cannot be permanently deleted (archive instead); archived
types still render on the past awards that used them.

## Permissions

`awards.view` (the feed), `awards.manage` (give / edit / remove recognitions);
`setup.award-types.view` / `setup.award-types.manage` (the configuration surface).
Built-in **HR Manager** gets all of them. The granting user is recorded on each award.

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
- **Retrieval:**
  - a question about a person carries their recognitions with the citations. One's
    **own** recognitions need no permission, since the mobile app already shows them
    to their owner;
  - a question about recognition that names nobody carries the feed. For
    `awards.manage`, it also carries the front-runner for each award type.

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

Nomination / approval workflows, points & reward redemption, peer-to-peer kudos, and
public recognition feeds for non-HR users.
