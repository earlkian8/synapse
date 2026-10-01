# Awards & Recognition module + Award Types setup

Adds the **Awards & Recognition** module (ERD §9): give employees recognitions from a
typed catalogue and see them in a chronological feed. Ships with its Company-Setup
configuration (**Award Types**), wiring the two existing sidebar placeholders. Reuses
the Benefits config + operational split, but the operational side is a flat recognition
feed (no per-record page). See
[ADR 0014](../decisions/0014-awards-and-recognition.md),
[module doc](../modules/awards.md) and [awards tables](../database/awards-tables.md).

## Highlights

- **Recognition feed.** `/awards` is a chronological list of awards (recipient,
  colour-tinted type badge, date, reason, granted-by) with KPIs and filter/search, plus
  a give-recognition dialog and inline edit / remove.
- **Typed, colourful catalogue.** Award types carry an accent colour + active flag;
  archived types still render on the past awards that used them (`withTrashed`), and a
  type that has been given out can't be permanently deleted.

## Backend

- **Migration** `…_create_awards_tables`: `award_types` (color + is_active, soft-deletes)
  and `employee_awards` (employee, type, awarded_on, reason, awarded_by). Tenant-scoped.
- **Models** `AwardType` + `EmployeeAward` (`awardType()` loaded `withTrashed`; +
  `Employee::awards`).
- **Controllers** `Setup\AwardTypeController` (index + CRUD + archive/restore/force) and
  `Awards\AwardController` (feed) + `EmployeeAwardController` (give/update/remove,
  recording `awarded_by`). Thin, FormRequest-validated, activity-logged.
- **Resources** `AwardTypeResource`, `EmployeeAwardResource`.
- **Routes** new `routes/awards.php` (required in `web.php`) + an `award-types` section
  in `routes/setup.php`. Permissions `awards.view` / `awards.manage` and
  `setup.award-types.view` / `setup.award-types.manage` added to `PermissionRegistry`;
  built-in HR Manager granted all four.
- **Seeder** `AwardSeeder` (5 colour-coded types + a spread of recognitions); wired into
  `DatabaseSeeder`.
- **Employee integration** `EmployeeController` eager-loads `awards.awardType`;
  `EmployeeResource` exposes `awards`.

## Frontend

- **Features** `features/awards` (types, routes, api, constants, components: stats,
  award-type badge, give-award dialog) and `features/award-types-config` (award-type
  form sheet with a colour-swatch picker).
- **Pages** `awards/index` (recognition feed + filters), `setup/award-types`
  (single-section config mirroring `setup/benefits`).
- **Employee detail** read-only **Awards** tab (alongside Benefits, Performance &
  Training).
- **Sidebar** Awards & Recognition (`awards.view`) and Award Types
  (`setup.award-types.view`) wired + gated.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint, Prettier and `vite build` all green; routes
  registered; migration + seeders run on Postgres (5 types, 20 awards, 13 recognised);
  the give → archive-type (still resolves via `withTrashed`) → force-delete-guard flow
  and the employee Awards-tab serialization validated via a rolled-back tinker
  transaction. Pest was **not** run locally (no `pdo_sqlite`).
- Out of scope this cut: nominations / approval workflows, points & reward redemption,
  peer-to-peer kudos, public recognition feeds, and an assistant capability.
