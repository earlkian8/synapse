# Module: Activity Logs

> Status: **Active** · Route prefix: `/system/activity-logs` · Sidebar: System → Activity Logs

A chronological, read-only audit trail of actions performed across the system.
Built to mirror the [User Management](./user-management.md) module's design
(stats cards, server-side table, bulk operations, slide-over detail, export) while
being **append-only by intent** — entries are written by the app, never created
through the UI.

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **Listing** | Server-side search, event filter, column sorting, pagination (10–100 / page). |
| **Stats** | Total events, today, this week, this month, creates, deletions. |
| **Detail** | Read-only slide-over: actor, event, description, target, request (IP/user-agent), changed properties, timing. |
| **Per-row actions** | View details, delete. |
| **Bulk actions** | Delete selected. |
| **Maintenance** | "Clear logs" (delete the whole trail) from the toolbar. |
| **Export** | CSV download honouring the current filters. |

---

## 2. Routes

Registered in [`routes/system.php`](../../server/routes/system.php) under
`['auth', 'verified']`, name prefix `system.activity-logs.*`.

| Method | URI | Name | Controller |
| --- | --- | --- | --- |
| GET | `/system/activity-logs` | `index` | `ActivityLogController@index` |
| GET | `/system/activity-logs/export` | `export` | `ActivityLogExportController` |
| POST | `/system/activity-logs/bulk` | `bulk` | `ActivityLogBulkActionController` |
| DELETE | `/system/activity-logs/clear` | `clear` | `ActivityLogController@clear` |
| DELETE | `/system/activity-logs/{activityLog}` | `destroy` | `ActivityLogController@destroy` |

> `clear` is declared before `{activityLog}` so the literal path wins over the
> wildcard for `DELETE`.

---

## 3. Data model — `activity_logs`

| Column | Notes |
| --- | --- |
| `log_name` | Category, e.g. `user_management`. Nullable, indexed. |
| `event` | Verb: `created`, `updated`, `activated`, `deactivated`, `password_reset`, `archived`, `restored`, `deleted`. Indexed. |
| `description` | Human-readable summary. |
| `causer_id` | FK → `users`, `nullOnDelete`. The actor (null = system). |
| `subject_type` / `subject_id` | Polymorphic target (`nullableMorphs`). |
| `subject_label` | Snapshot of the subject's name, survives the subject's deletion. |
| `properties` | JSON — e.g. changed attribute keys, bulk action metadata. |
| `ip_address` / `user_agent` | Request context. |
| `created_at` | Indexed for time-based queries. |

`App\Models\ActivityLog`: `causer()` belongsTo `User` **withTrashed** (so archived
actors still render); `subject()` morphTo; `scopeSearch()` across description /
event / IP / subject label / causer name & email.

---

## 4. Backend architecture

```
app/
├── Http/Controllers/ActivityLog/
│   ├── ActivityLogController.php          # index, destroy, clear
│   ├── ActivityLogBulkActionController.php# invokable: bulk delete
│   └── ActivityLogExportController.php     # invokable: streamed CSV
├── Http/Requests/ActivityLog/
│   └── BulkActivityLogActionRequest.php
├── Http/Resources/ActivityLogResource.php  # actor + subject + request shape
├── Queries/
│   ├── ActivityLogsIndexQuery.php          # filter (event) + sort + paginate
│   └── ActivityLogStatistics.php           # stat-card aggregates
├── Models/ActivityLog.php
└── Support/ActivityLogger.php              # the write API
```

### Writing logs — `ActivityLogger`

A single static entry point captures the causer (`auth()->id()`), IP and
user-agent from the current request:

```php
ActivityLogger::log(
    event: 'created',
    description: "Created user {$user->full_name}",
    subject: $user,
    properties: ['changed' => [...]],
    logName: 'user_management',
    subjectLabel: $user->full_name,
);
```

### User Management integration

Every mutating action in User Management now records an entry:

| Action | Event |
| --- | --- |
| `UserController@store` | `created` |
| `UserController@update` | `updated` (+ `properties.changed`) |
| `UserController@destroy` | `archived` |
| `UserController@restore` | `restored` |
| `UserController@forceDelete` | `deleted` |
| `UserStatusController@update` | `activated` / `deactivated` |
| `UserPasswordController@update` | `password_reset` |
| `UserBulkActionController` | one summary entry per sweep (`properties.count`, `ids`) |

Failed self-action guards (e.g. archiving your own account) do **not** log.

---

## 5. Frontend architecture

Feature-folder convention, mirroring `features/users/`.

```
resources/js/
├── pages/system/activity-logs/index.tsx     # Inertia page (read-only orchestration)
└── features/activity-logs/
    ├── types.ts · routes.ts · constants.ts
    ├── hooks/use-activity-logs-filters.ts    # URL-owned table state
    └── components/
        ├── activity-stats.tsx · activity-toolbar.tsx · activity-table.tsx
        ├── activity-row-actions.tsx · activity-bulk-actions-bar.tsx
        ├── activity-pagination.tsx · activity-detail-sheet.tsx
        ├── activity-event-badge.tsx · actor-cell.tsx · confirm-dialog.tsx
```

Query params: `search`, `event`, `sort` (`event` | `created_at`), `direction`,
`per_page`, `page` — defaults omitted from the URL, same as User Management.

---

## 6. Testing

`tests/Feature/ActivityLog/ActivityLogTest.php` — index render, event filter,
search, single/bulk delete, clear, CSV export, and that User Management actions
(create, archive, password reset, bulk) write the expected log entries.

(Feature suite needs `pdo_sqlite` / CI; see the User Management doc for the local note.)
