# 2026-06-10 — Activity Logs module

A new, read-only audit-trail module that mirrors the User Management design, plus
activity logging wired into every User Management mutation.

## Summary

- New **Activity Logs** module at `/system/activity-logs` (stats, server-side
  filtered table, detail drawer, bulk delete, clear, CSV export).
- A reusable **`ActivityLogger`** write API and an `activity_logs` table.
- **User Management** controllers now record an activity log for each action.

## Backend

- Migration `…_create_activity_logs_table` — `log_name`, `event`, `description`,
  `causer_id` (FK → users, nullOnDelete), `nullableMorphs('subject')`,
  `subject_label`, `properties` (json), `ip_address`, `user_agent`, timestamps.
- `App\Models\ActivityLog` — `causer()` (withTrashed), `subject()` morphTo,
  `scopeSearch()`.
- `App\Support\ActivityLogger::log(...)` — captures causer / IP / user-agent.
- `ActivityLogController` (index/destroy/clear), `ActivityLogBulkActionController`,
  `ActivityLogExportController`, `BulkActivityLogActionRequest`,
  `ActivityLogResource`, `ActivityLogsIndexQuery`, `ActivityLogStatistics`.
- Routes added to `routes/system.php` (`system.activity-logs.*`).
- Logging added to `UserController` (created/updated/archived/restored/deleted),
  `UserStatusController` (activated/deactivated), `UserPasswordController`
  (password_reset), and `UserBulkActionController` (one summary entry per sweep).

## Frontend

- `features/activity-logs/` — types, routes, constants, filter hook, and components
  (stats, toolbar, table, row actions, bulk bar, pagination, detail sheet, event
  badge, actor cell, confirm dialog), mirroring `features/users/`.
- `pages/system/activity-logs/index.tsx` — read-only listing page.
- Sidebar "Activity Logs" link fixed to point at `/system/activity-logs`.

## Tests

`tests/Feature/ActivityLog/ActivityLogTest.php` — listing/filter/search, single &
bulk delete, clear, export, and assertions that User Management actions emit logs.

## Verification

`tsc`, ESLint and Pint clean; `npm run build` succeeds (`activity-logs` chunk);
logger, index query, stats and resource confirmed via a bootstrap script.
