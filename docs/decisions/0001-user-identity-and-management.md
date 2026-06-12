# 0001 — User identity & management foundation

- **Status:** Accepted
- **Date:** 2026-06-10
- **Supersedes:** —

## Context

The starter `users` table carried a single `name` column and the minimum auth
fields. NEXO is an HR ERP where the user record is the backbone of employees,
payroll, recruitment, etc. We needed structured identity, HR profile data, and an
administrative management surface — without losing data when an account leaves.

## Decisions

### 1. Split `name` into `first_name` / `middle_name` / `last_name` (+ `suffix`)
HR systems sort, filter, and address people by name parts. A single `name` string
can't support that. `middle_name` and `suffix` are nullable; `first_name` and
`last_name` are required. A `full_name` accessor provides the combined display value
so the frontend never reassembles names. The migration backfills existing data by
splitting the old `name` and is fully reversible.

### 2. Make `password` nullable
Accounts are often provisioned by an admin before the person sets a password
(invite-later / future SSO). A nullable password models "account exists, no
credential yet"; the UI exposes this safely as `has_password`.

### 3. Add an HR profile + account-state column set
`phone_number`, `profile_photo`, `employee_id` (unique), `is_active`,
`last_login_at`, `password_changed_at`. `is_active` is a first-class boolean
(default true) rather than inferred state, so deactivation is explicit and queryable.

### 4. Soft-delete archiving instead of hard delete by default
`DELETE` archives (`deleted_at`); a separate **force-delete** permanently removes.
HR data should be recoverable — an accidental removal must not be irreversible.
This gives a three-stage lifecycle: **active/inactive → archived → permanently deleted**.

### 5. Thin controllers + query objects + a single resource
Listing logic lives in `UsersIndexQuery` (filter/sort/paginate) and `UserStatistics`
(aggregates); each non-CRUD action is its own invokable controller; `UserResource`
is the one serialization contract. This keeps controllers readable and the
client/server data shape in one place.

### 6. URL-owned table state
Search, status, sort, and paging live in the query string and are resolved
server-side on every request (Inertia partial reloads). The client holds only UI
state. Result: shareable/bookmarkable views, correct back-button behaviour, and no
client/server drift.

### 7. Administrator self-protection
The acting admin cannot deactivate, archive, or permanently delete their own
account (individually or via bulk). Prevents accidental self-lockout.

## Consequences

- **Positive:** structured, queryable identity; recoverable lifecycle; clean
  separation of concerns; portable across Postgres (app) and SQLite (tests).
- **Trade-offs:** more columns and migrations to maintain; soft deletes require
  `onlyTrashed()` / `withTrashed()` awareness in any future query touching users.
- **Follow-ups:** roles & permissions, departments relationship, invitation flow,
  and an audit log are designed-for but not yet implemented (see the module doc).
