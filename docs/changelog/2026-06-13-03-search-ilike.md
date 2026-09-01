# 2026-06-13 — Case-insensitive search via Postgres `ILIKE`

Every free-text search scope now matches with the database's native
case-insensitive operator instead of the portable `lower(col) LIKE ?`
workaround. This backs both the module index/list pages and the agentic
assistant's name resolution (`matchByTokens`).

## What changed

- Search scopes on `Employee`, `Applicant`, `JobPosting`, `LeaveType`, `User`,
  `Department`, `Role`, and `ActivityLog` now use `col ILIKE %term%`.
- Dropped the per-column `lower(...)` wrap and the `mb_strtolower()` on the
  needle — `ILIKE` is case-insensitive on its own.
- Moved from raw SQL (`orWhereRaw('lower(col) like ?')`) to the query builder's
  operator form (`orWhere($col, $like, $needle)`).

## Driver-aware operator

The operator is chosen from the active connection so the change is safe across
environments:

- **Postgres** (production) → `ILIKE`, the native case-insensitive match.
- **SQLite** (the in-memory test suite, which has no `ILIKE`) → `LIKE`, whose
  `LIKE` is already case-insensitive for ASCII.

```php
$like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
```

## Notes

- `Module::resolveId` is intentionally left on `lower(col) = ?` — it's an exact
  equality resolver, not a search, and `ILIKE` would treat `%` / `_` in a name
  or code as wildcards.
- No behavioural change for users: matching was already case-insensitive. This
  is a clarity/idiom cleanup that leans on Postgres directly.
