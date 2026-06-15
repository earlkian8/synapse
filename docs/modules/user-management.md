# Module: User Management

> Status: **Active** · Route prefix: `/system/users` · Sidebar: System → User Management

Administrative management of every account in the system: create, edit, search,
filter, archive/restore, reset passwords, run bulk operations, and export. Built
for an HR ERP — accounts are never silently lost (soft-delete archiving), and the
acting administrator is protected from locking themselves out.

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **Listing** | Server-side search, status filter, column sorting, pagination (10–100 / page). |
| **Stats** | Live headline cards: total, active, inactive, unverified, new-this-month, archived. |
| **Create / Edit** | Slide-over form with sectioned layout, inline validation, **profile photo upload** (preview + remove), optional password (invite-later flow) + secure password generator. A **confirmation email** is sent on create and whenever the email changes. |
| **View** | Read-only profile drawer (contact, security, activity). |
| **Per-row actions** | View, edit, activate/deactivate, reset password, archive, restore, delete permanently. |
| **Bulk actions** | Activate, deactivate, archive (active scope); restore, delete (archived scope). |
| **Lifecycle** | Soft-delete **archive** → **restore** → **permanent delete**. |
| **Security signals** | Email-verified indicator, two-factor (2FA) badge, password-set state. |
| **Export** | CSV download honouring the **currently applied filters**. |
| **Login tracking** | `last_login_at` stamped automatically on every successful login. |
| **Guards** | Admin cannot archive, deactivate, or permanently delete their own account (single or bulk). |

---

## 2. Routes

Defined in [`server/routes/system.php`](../../server/routes/system.php), all under
middleware `['auth', 'verified']` and name prefix `system.users.*`.

| Method | URI | Name | Controller | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/system/users` | `index` | `UserController@index` | Listing page (Inertia). |
| POST | `/system/users` | `store` | `UserController@store` | Create a user. |
| GET | `/system/users/export` | `export` | `UserExportController` | CSV of filtered users. |
| POST | `/system/users/bulk` | `bulk` | `UserBulkActionController` | Batch action over IDs. |
| PATCH | `/system/users/{user}` | `update` | `UserController@update` | Update a user. |
| POST | `/system/users/{user}/resend-verification` | `resend-verification` | `UserController@resendVerification` | Resend the email-confirmation link. |
| DELETE | `/system/users/{user}` | `destroy` | `UserController@destroy` | Archive (soft delete). |
| PATCH | `/system/users/{user}/status` | `status` | `UserStatusController@update` | Activate / deactivate. |
| PUT | `/system/users/{user}/password` | `password` | `UserPasswordController@update` | Admin password reset. |
| PATCH | `/system/users/{user}/restore` | `restore` | `UserController@restore` | Restore an archived user. |
| DELETE | `/system/users/{user}/force` | `force-delete` | `UserController@forceDelete` | Permanent delete. |

The frontend mirrors these in a single typed map:
[`resources/js/features/users/routes.ts`](../../server/resources/js/features/users/routes.ts).

---

## 3. Backend architecture

Single-responsibility classes, grouped by concern.

```
server/app/
├── Http/
│   ├── Controllers/UserManagement/
│   │   ├── UserController.php            # index, store, update, destroy, restore, forceDelete
│   │   ├── UserStatusController.php      # activate / deactivate
│   │   ├── UserPasswordController.php    # admin password reset
│   │   ├── UserBulkActionController.php  # invokable: bulk activate/deactivate/archive/restore/delete
│   │   └── UserExportController.php      # invokable: streamed CSV
│   ├── Requests/UserManagement/
│   │   ├── StoreUserRequest.php
│   │   ├── UpdateUserRequest.php          # unique email/employee_id ignore current
│   │   ├── BulkUserActionRequest.php      # action ∈ ACTIONS, ids[] required
│   │   └── UpdateUserPasswordRequest.php
│   └── Resources/
│       └── UserResource.php               # single serialization contract for the frontend
├── Queries/
│   ├── UsersIndexQuery.php                # filter + sort + paginate from the Request
│   └── UserStatistics.php                 # stat-card aggregates
└── Models/User.php                        # SoftDeletes, full_name accessor, scopeSearch
```

### Listing pipeline

`UserController@index` delegates to `UsersIndexQuery::paginate()`, which:

1. Applies the **status** scope (`active`, `inactive`, `unverified`, `archived`, or `all`).
   `archived` switches the query to `onlyTrashed()`.
2. Applies **search** via the `User::scopeSearch()` model scope — a case-insensitive
   `col ILIKE %term%` across `first_name, middle_name, last_name, suffix, email,
   employee_id, phone_number` (Postgres' native `ILIKE`, falling back to `LIKE` on
   SQLite — whose `LIKE` is already case-insensitive — so the test suite stays portable).
3. Applies a whitelisted **sort** column + direction, with `id desc` as a stable tiebreaker.
4. Paginates with `withQueryString()` so filters survive page changes.

Allowed values are centralised as constants on `UsersIndexQuery`
(`SORTABLE`, `STATUSES`, `PER_PAGE`) and reused by the controller and export.

### Serialization contract

`UserResource` is the **only** place a user is shaped for the client. Beyond raw
columns it exposes derived fields the UI relies on:

- `full_name`, `initials`
- `status` — one of `active | inactive | archived`
- `email_verified`, `two_factor_enabled`, `has_password` (booleans, never leak secrets)
- `last_login_human`, `created_human` (relative strings) alongside ISO timestamps

Inertia serializes `UserResource::collection($paginator)` to
`{ data, links: {first,last,prev,next}, meta: {current_page, last_page, per_page,
total, from, to, links[]} }` — matched by the `Paginated<T>` TS type.

### Login tracking

`AppServiceProvider::recordLastLogin()` listens for `Illuminate\Auth\Events\Login`
and stamps `last_login_at` with `saveQuietly()` (no model events / no `updated_at` churn).

### Self-protection

`destroy`, `forceDelete`, `UserStatusController` (deactivate), and
`UserBulkActionController` all reject operations targeting the authenticated user,
flashing an error toast instead.

---

## 4. Frontend architecture

Feature-folder convention — everything the module owns lives under
`features/users/`, with the thin Inertia page under `pages/`.

```
server/resources/js/
├── pages/system/users/index.tsx           # Inertia page: state orchestration only
└── features/users/
    ├── types.ts                            # ManagedUser, UserStats, Paginated<T>, …
    ├── routes.ts                           # endpoint map
    ├── constants.ts                        # STATUS_FILTERS, PER_PAGE_OPTIONS, DEFAULT_FILTERS
    ├── hooks/use-users-filters.ts          # pushes query-string state via Inertia partial reloads
    └── components/
        ├── users-stats.tsx                 # stat cards
        ├── users-toolbar.tsx               # debounced search, status filter, export, add
        ├── users-table.tsx                 # sortable headers, selection, empty state
        ├── user-row-actions.tsx            # per-row dropdown
        ├── bulk-actions-bar.tsx            # appears when rows are selected
        ├── users-pagination.tsx            # page window + per-page
        ├── user-form-sheet.tsx             # create / edit slide-over
        ├── user-detail-sheet.tsx           # read-only profile drawer
        ├── reset-password-dialog.tsx       # admin password reset
        ├── confirm-dialog.tsx              # reusable destructive-action confirm
        ├── user-avatar.tsx
        └── user-status-badge.tsx
```

Two **shared UI primitives** were added for this module and are reusable elsewhere:
`components/ui/table.tsx` and `components/ui/switch.tsx`.

### State model

The page holds only UI state (selection, which sheet/dialog is open, processing).
All **data** state (search, status, sort, paging) lives in the URL and is owned by
the server — `use-users-filters` writes it through `router.get(..., { only: ['users','filters'] })`
partial reloads, and the server is the source of truth on every render. Selection
is cleared (render-phase, not via effect) whenever the result-set signature changes.

### Query parameters

| Param | Values | Default |
| --- | --- | --- |
| `search` | free text | — |
| `status` | `all`, `active`, `inactive`, `unverified`, `archived` | `all` |
| `sort` | `first_name`, `last_name`, `email`, `employee_id`, `last_login_at`, `created_at` | `created_at` |
| `direction` | `asc`, `desc` | `desc` |
| `per_page` | `10`, `15`, `25`, `50`, `100` | `10` |
| `page` | integer | `1` |

Defaults are omitted from the URL to keep links clean.

---

## 5. Data model

The user record is documented in detail in
[`docs/database/users-table.md`](../database/users-table.md). Key fields surfaced by
this module: `first_name`, `middle_name`, `last_name`, `suffix`, `email`,
`phone_number`, `employee_id` (unique), `is_active`, `email_verified_at`,
`profile_photo`, `last_login_at`, `password_changed_at`, `deleted_at` (archive).

**Form coverage:** the create/edit form edits name parts, email, phone, active
state, and the profile photo. `employee_id` is **displayed** (table + detail drawer)
but is not set through this form — it is provisioned by the HR/employee module.
Profile photos are stored on the `public` disk under `profile-photos/` (requires
`php artisan storage:link`); `UserResource` returns a full URL. The form has **no**
"mark verified" control — verification is proven by the user via the emailed link
(see below).

---

## 5a. Email verification (confirmation)

The model implements `Illuminate\Contracts\Auth\MustVerifyEmail`, so the existing
`verified` middleware **enforces** confirmation: an unverified user is bounced to
the verification notice page and cannot use the app until they click the link.

- **On create** the new account starts unverified and a branded confirmation email
  is sent to its address.
- **On email change** the account is reset to unverified and a fresh link is sent to
  the new address.
- **Resend** — `UserController@resendVerification` (per-row action + a button in the
  detail drawer, shown only for unverified accounts) re-issues the link; gated by
  `users.update`.
- There is **no admin bypass** — ownership must be proven via the email. A backfill
  migration (`…_backfill_email_verified_at_for_existing_users`) marks every account
  that existed when the feature shipped as verified, so enforcement applies only to
  new/re-emailed accounts and nobody is locked out.

The verification link, the `Registered`-event auto-send (registration), and Fortify's
resend endpoint are the framework standards — we only **brand** the email, in
`AppServiceProvider` via `VerifyEmail::toMailUsing()`. It is sent **synchronously**
(not queued) so delivery never depends on a running queue worker — a silently dropped
email would lock the user out. Transport failures are caught so a mail outage can't
break the create/update itself; the admin sees a warning toast and can resend.

Delivery uses the app's default mailer — **Brevo** over SMTP (`smtp-relay.brevo.com`),
configured in `.env` (`MAIL_*`).

---

## 6. Testing

- `server/tests/Feature/UserManagement/UserManagementTest.php` — HTTP-level coverage of
  listing, filtering, sorting, CRUD, status toggle, password reset, archive/restore/force-delete,
  bulk actions, the self-protection guards, and CSV export.
- `server/tests/Unit/UserModelTest.php` — DB-free checks of the `full_name` accessor and
  `is_active` boolean cast.

> ⚠️ The Feature suite runs on SQLite `:memory:` (see `phpunit.xml`). The local PHP
> build currently lacks the `pdo_sqlite` extension, so Feature tests run in CI / any
> environment with that extension enabled. The Unit test is DB-free and runs anywhere.

Run: `php artisan test --filter=UserManagement` / `--filter=UserModel`.

---

## 7. Extending the module

- **Roles & permissions:** add a `role` relationship + a `role` filter in
  `UsersIndexQuery`, a column in `users-table.tsx`, and a select in the form sheet.
  Gate the controllers with a policy.
- **Departments:** same pattern — relationship, filter, column, form field.
- **Invitations:** the schema already allows a null password (`has_password`),
  so an "invite" flow can create the account and email a set-password link.
- **Audit log:** the Activity Logs module can subscribe to user model events.
