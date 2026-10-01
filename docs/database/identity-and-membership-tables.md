# Database: identity & membership tables

How a person gets into a workspace and signs in. A `users` row is a global identity
with no tenant column. These tables connect it to organisations, to roster lines and
to devices. See [Multi-tenancy](../modules/multi-tenancy.md),
[ADR 0023](../decisions/0023-identity-and-organization-membership.md) and
[ADR 0026](../decisions/0026-self-served-identity-and-workspace-join.md). The `users`
table itself is in [users table](./users-table.md) and the tenant in
[`organizations`](./organizations-table.md).

## `organization_user`

Membership: which organisations an identity may act in. Created by
`2026_06_28_100000_decouple_user_identity_from_organization`, which also dropped
`users.organization_id`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Cascade on delete. |
| `user_id` | FK → users | Cascade on delete. |
| `is_default` | boolean | The workspace a fresh login lands in. |
| `joined_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** unique `(organization_id, user_id)`.

Read through `User::memberships()` and `Organization::members()`. Written only through
`OrganizationProvisioner::addMember()`: at registration, by `OrganizationProvisioner::admit()`
(the single path for an accepted invitation and an approved join request), and when an
administrator creates or imports accounts. A person's first membership becomes their
default. `SetCurrentOrganization` validates the active workspace against this table on
every request.

## `employee_invitations`

A claim ticket for one roster line: HR points at an `employees` row and invites the
person to link their account to it. Created by
`2026_08_10_000000_create_workspace_join_and_employee_invitations`. Tenant-scoped.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | Cascade on delete. |
| `email` | string | Where it was sent. Indexed. |
| `token` | string(64), unique | The **sha256** of the emailed link's token. The plain token is never stored. |
| `code` | string(16), unique | The short code a person can retype in the web or mobile app (8 characters from `JoinCode::ALPHABET`). Unique **globally**, because it is redeemed before a tenant is bound. |
| `invited_by` | FK → users, nullable | Null on delete. |
| `expires_at` | timestamp | 14 days after issue (`EmployeeInvitations::EXPIRES_AFTER_DAYS`). |
| `accepted_at` / `accepted_by` | timestamp / FK → users, nullable | Set when someone redeems it. |
| `revoked_at` | timestamp, nullable | Set when HR withdraws it, or when a new invitation supersedes it. |
| timestamps | | |

**Indexes:** `(organization_id, employee_id)`, `email`.

**Status is derived, never stored** (`EmployeeInvitation::status()`): `accepted`, else
`revoked`, else `expired`, else `pending`. Issuing a new invitation revokes any
outstanding one for the same roster line first, so exactly one code works at a time.
Redeeming deliberately uses `withoutGlobalScopes()`, because it happens outside any
tenant. Written only by `Support\EmployeeInvitations`.

## `organization_join_requests`

Someone who typed the organisation's join code but could not be matched to a roster
line automatically. A registered email that matches an unclaimed roster line is
admitted at once and never creates a row here. Same migration as above.
Tenant-scoped.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `user_id` | FK → users | Cascade on delete. |
| `employee_id` | FK → employees, nullable | The roster line HR picks when approving. Null on delete. |
| `status` | string | `pending` / `approved` / `declined`. Default `pending`. |
| `decline_reason` | string, nullable | |
| `reviewed_by` | FK → users, nullable | Null on delete. |
| `reviewed_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** unique `(organization_id, user_id)`; `(organization_id, status)`.

One row per person per organisation: asking again after a decline revives the same
row. Written by `Support\WorkspaceJoin`. The organisation's own `join_code` and
`join_code_enabled` live on [`organizations`](./organizations-table.md).

## `passkeys`

WebAuthn credentials for passwordless sign-in, managed by Laravel Fortify's passkey
support (the `passkeys` feature in `config/fortify.php`). Global, like `users`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `user_id` | FK → users | Cascade on delete. Indexed. |
| `name` | string | The label the person gave it. |
| `credential_id` | string, unique | |
| `credential` | json | The public-key credential. |
| `last_used_at` | timestamp, nullable | |
| timestamps | | |

## `personal_access_tokens.organization_id`

The mobile app signs in with a Sanctum personal access token
([Mobile app](../modules/mobile-app.md)). The decoupling migration added a nullable
`organization_id` → `organizations` (null on delete) to the standard Sanctum table, so
each token is bound to the one workspace the app is acting in. `MobileSession` sets it
when it mints a token. It is null for a person who has not joined a company yet.
Switching workspace (`POST /api/auth/switch`) checks the membership, issues a fresh
token bound to the chosen organisation and revokes the old one. Every request is
validated against `organization_user`.
