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

## Out of scope (this cut)

Nomination / approval workflows, points & reward redemption, peer-to-peer kudos,
public recognition feeds for non-HR users, and an assistant capability.
