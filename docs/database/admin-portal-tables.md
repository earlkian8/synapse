# Database: admin portal tables

The tables behind the [admin portal](../modules/admin-portal.md), created by
`2026_10_13_000000_create_admin_portal_tables`
([ADR 0085](../decisions/0085-a-platform-admin-portal.md)). Neither is tenant-scoped:
the portal stands above every organisation, and neither table has `organization_id`.

## `admins`

The people who run SYNAPSE. A separate identity from `users`: no memberships, no roles,
its own guard.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | |
| `email` | string, unique | Stored lower-cased by the model, so uniqueness is case-insensitive. |
| `password` | string | Bcrypt (`hashed` cast). |
| `role` | string(16) | `super_admin` or `admin`. Default `admin`. |
| `is_active` | boolean | Default true. A deactivated admin cannot sign in, and their open session ends at its next request. |
| `must_change_password` | boolean | Default true. Set when another admin issued the password (a new account, a reset). Cleared when the admin chooses their own. Accounts made with `admin:create` start false. |
| `two_factor_secret` | text, nullable | The TOTP secret, `encrypted` cast. Written only once a code has confirmed it. |
| `two_factor_confirmed_at` | timestamp, nullable | When the authenticator was confirmed. Both columns null: set up at the next sign-in. |
| `last_login_at` | timestamp, nullable | Stamped when a session opens. |
| `last_login_ip` | string(45), nullable | |
| `password_changed_at` | timestamp, nullable | When the admin last chose their own password. |
| `created_by` | FK → admins, nullable | Null on delete, and for accounts made at the console. |
| timestamps | | |

Portal sessions are rows in `personal_access_tokens` with `tokenable_type` =
`App\Models\Admin`, the `admin` ability, an `expires_at`, and `organization_id` null
(see [identity & membership tables](./identity-and-membership-tables.md)).

## `admin_activity_logs`

What admins did, append-only. Nothing in the application updates or deletes a row.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `admin_id` | FK → admins, nullable | Null on delete; null for a console action or a failed sign-in for an unknown email. |
| `actor_name` | string, nullable | The admin's name at the time, kept after the account is gone. |
| `actor_email` | string, nullable | Their email at the time, or the address tried in a failed sign-in. |
| `event` | string(40), indexed | One of `AdminActivityLog::EVENTS`: `sign_in`, `sign_in_failed`, `sign_out`, `two_factor_enrolled`, `two_factor_failed`, `password_changed`, `sessions_revoked`, `admin_created`, `admin_updated`, `admin_activated`, `admin_deactivated`, `admin_password_reset`, `admin_two_factor_reset`, `admin_deleted`, and `problem_report_updated` (ADR 0096, subject a `ProblemReport`, label its reference). |
| `description` | text | A sentence for people, e.g. "Reset Ben Lim's password". |
| `subject_type`, `subject_id` | nullable morph | The admin acted on, if any. |
| `subject_label` | string, nullable | "Name (email)" of the subject at the time. |
| `properties` | json, nullable | Event details: `reason` of a failed sign-in, `changes` of an update (`{field: {from, to}}`), `sessions_ended`, `via: console`; for a report, `status` (`{from, to}`), `replied`, `note_changed`. |
| `ip_address` | string(45), nullable | |
| `user_agent` | text, nullable | Truncated to 512 characters. |
| `created_at` | timestamp, indexed | No `updated_at`: rows never change. |
