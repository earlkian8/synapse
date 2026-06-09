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
| `password` | string | yes | **Nullable** — supports invited / SSO-provisioned accounts. Hashed via cast. |
| `phone_number` | string | yes | |
| `profile_photo` | string | yes | Path / URL to avatar. |
| `employee_id` | string | yes | **Unique** when present (nullable uniques allow many NULLs on Postgres). |
| `is_active` | boolean | no | Default `true`. Inactive users keep data but cannot sign in. |
| `last_login_at` | timestamp | yes | Stamped on each successful login. |
| `password_changed_at` | timestamp | yes | Set on create-with-password and admin reset. |
| `remember_token` | string | yes | Laravel auth. |
| `two_factor_secret` | text | yes | Fortify 2FA. |
| `two_factor_recovery_codes` | text | yes | Fortify 2FA. |
| `two_factor_confirmed_at` | timestamp | yes | Fortify 2FA. |
| `created_at` / `updated_at` | timestamp | yes | |
| `deleted_at` | timestamp | yes | **Soft delete** — present ⇒ archived. |

## Model (`App\Models\User`)

- **Fillable:** `first_name, middle_name, last_name, suffix, email, password,
  phone_number, profile_photo, employee_id, is_active, last_login_at, password_changed_at`.
- **Hidden:** `password, two_factor_secret, two_factor_recovery_codes, remember_token`.
- **Casts:** `email_verified_at`, `two_factor_confirmed_at`, `last_login_at`,
  `password_changed_at` → `datetime`; `password` → `hashed`; `is_active` → `boolean`.
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

## Notes

- Tests run on SQLite `:memory:`; the app runs on PostgreSQL. All migrations and the
  `scopeSearch` query are written to behave identically on both (no Postgres-only SQL).
- `full_name` is computed, not stored — never write to it.
