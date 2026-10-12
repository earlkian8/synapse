# Table: `users`

The central identity record. Originally a minimal `name / email / password` table,
it was restructured into a structured identity + HR profile + soft-deletable account.
See [ADR 0001](../decisions/0001-user-identity-and-management.md) for the rationale.

## Columns

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | bigint | no | PK. |
| `first_name` | string | no | Required. |
| `middle_name` | string | yes | Optional. |
| `last_name` | string | no | Required. |
| `suffix` | string | yes | e.g. Jr., Sr., III. |
| `email` | string | no | Unique. |
| `email_verified_at` | timestamp | yes | Set when the email is verified. |
| `email_verification_code` | string | yes | **Hashed** one-time code the address is confirmed with ([ADR 0033](../decisions/0033-email-verification-by-code.md)). Not fillable, `#[Hidden]`, cleared once spent. |
| `email_verification_code_expires_at` | timestamp | yes | When that code stops working (`auth.verification.code_expire`, 10 minutes). |
| `password` | string | yes | **Nullable** — supports invited / SSO-provisioned accounts. Hashed via cast. |
| `phone_number` | string | yes | |
| `profile_photo` | string | yes | Path / URL to avatar. |
| `employee_id` | string | yes | **Unique** when present (nullable uniques allow many NULLs on Postgres). |
| `is_active` | boolean | no | Default `true`. Inactive users keep data but cannot sign in. |
| `last_login_at` | timestamp | yes | Stamped on each successful login. |
| `password_changed_at` | timestamp | yes | Set on create-with-password and admin reset. |
| `tour_finished_at` | timestamp | yes | When the first-run tour was finished or skipped ([ADR 0060](../decisions/0060-a-first-run-tour-of-the-app.md)). Null ⇒ the tour is still offered. Not fillable, `#[Hidden]`; written by `ProductTour::finish` without touching `updated_at`. |
| `tour_outcome` | string(16) | yes | `completed` or `skipped` — the first answer, kept. Null on accounts back-filled by the migration (never offered). Not fillable, `#[Hidden]`. |
| `tour_version` | smallint unsigned | yes | The web tour version last answered ([ADR 0080](../decisions/0080-tour-versions-per-stop-runs-and-a-tour-in-the-app.md)). Lower than `ProductTour::VERSION` ⇒ a *What's new* is offered. Back-filled to 1 for everybody who had finished. Not fillable, `#[Hidden]`. |
| `mobile_tour_version` | smallint unsigned | yes | The mobile app's tour version answered (ADR 0080). Null ⇒ the app offers its tour. Not fillable, `#[Hidden]`. |
| `mobile_tour_finished_at` | timestamp | yes | When the app's tour was first answered. Not fillable, `#[Hidden]`. |
| `remember_token` | string | yes | Laravel auth. |
| `two_factor_secret` | text | yes | Fortify 2FA. |
| `two_factor_recovery_codes` | text | yes | Fortify 2FA. |
| `two_factor_confirmed_at` | timestamp | yes | Fortify 2FA. |
| `created_at` / `updated_at` | timestamp | yes | |
| `deleted_at` | timestamp | yes | **Soft delete** — present ⇒ archived. |

## Model (`App\Models\User`)

- **Fillable:** `first_name, middle_name, last_name, suffix, email, password,
  phone_number, profile_photo, employee_id, is_active, last_login_at, password_changed_at`.
- **Hidden:** `password, two_factor_secret, two_factor_recovery_codes, remember_token,
  email_verification_code, tour_finished_at, tour_outcome, tour_version,
  mobile_tour_version, mobile_tour_finished_at` (tour state reaches the browser only as the
  shared `auth.tour`, and the app as the session's `user.tour`).
- **Casts:** `email_verified_at`, `two_factor_confirmed_at`, `last_login_at`,
  `password_changed_at`, `tour_finished_at`, `mobile_tour_finished_at` → `datetime`;
  `tour_version`, `mobile_tour_version` → `integer`; `password` → `hashed`; `is_active` → `boolean`.
- **Traits:** `SoftDeletes` (+ Fortify passkey/2FA, Notifiable, HasFactory).
- **Accessors:** `full_name` (appended) — `"first middle last suffix"` trimmed.
- **Scopes:** `scopeSearch($term)` — case-insensitive multi-column search.

## Migration history

| Migration | Change |
| --- | --- |
| `0001_01_01_000000_create_users_table` | Original `name / email / password` table. |
| `2026_06_09_000000_split_users_name_into_parts` | Replaced `name` with `first_name`, `middle_name` (nullable), `last_name`; data-safe backfill (splits the legacy name) + reversible `down()`. |
| `2026_06_09_000001_add_profile_fields_to_users_table` | Added `suffix`, `phone_number`, `profile_photo`, `employee_id` (unique), `is_active` (default true), `last_login_at`, `password_changed_at`; made `password` nullable. |
| `2026_06_10_000000_add_soft_deletes_to_users_table` | Added `deleted_at`. |
| `2026_09_29_000000_add_product_tour_to_users` | Added `tour_finished_at`, `tour_outcome` (ADR 0060); back-filled every existing account as finished (query builder, so `updated_at` is untouched). |
| `2026_10_11_040000_add_tour_versions_and_runs` | Added `tour_version` (back-filled to 1 where the tour was finished, by query builder), `mobile_tour_version`, `mobile_tour_finished_at`; created `product_tour_runs` (below); granted `product-tour.view` to HR Manager (ADR 0080). |

## `product_tour_runs`

One run of the web or mobile tour, for System → Product Tour's per-stop numbers
([ADR 0080](../decisions/0080-tour-versions-per-stop-runs-and-a-tour-in-the-app.md)).
Tenant-scoped (`BelongsToOrganization`); left out of the data export as usage telemetry.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint (PK) | no | |
| `user_id` | FK → users | no | `cascadeOnDelete`. |
| `organization_id` | FK → organizations | yes | The workspace the run was in. `nullOnDelete`. |
| `platform` | string(8) | no | `web` or `mobile`. |
| `version` | smallint unsigned | no | The tour's version when it ran. |
| `mode` | string(12) | no | `first`, `whats_new` or `replay`. |
| `steps` | json | no | The stop ids this run showed, in order — a role that cannot open a section never sees its stop. |
| `furthest_index` | smallint unsigned | no | The furthest stop reached, as an index into `steps`. Default 0; never lowered by going back. |
| `last_step` | string(40) | yes | The id of the stop shown last. |
| `outcome` | string(12) | yes | `completed` or `skipped`; null while running, or when abandoned (no answer an hour after starting). |
| `started_at` / `finished_at` | timestamp | no / yes | |
| `created_at` / `updated_at` | timestamp | yes | |

**Indexes:** `(organization_id, platform, version)`, `(user_id, started_at)`.

## Notes

- Tests run on SQLite `:memory:`; the app runs on PostgreSQL. All migrations and the
  `scopeSearch` query are written to behave identically on both (no Postgres-only SQL).
- `full_name` is computed, not stored — never write to it.
