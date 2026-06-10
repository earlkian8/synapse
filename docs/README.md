# STAFFA Documentation

Project documentation for the STAFFA HR ERP. This folder is the single source of
truth for **how and why** the system is built — complementing the inline code and
commit history rather than repeating them.

## How this folder is organised

| Folder | Purpose | Naming |
| --- | --- | --- |
| [`modules/`](./modules) | One document per functional module (User Management, Payroll, Recruitment, …). Describes features, routes, backend + frontend architecture, and how to extend it. | `kebab-case.md`, named after the module (`user-management.md`). |
| [`database/`](./database) | Schema references for important tables — columns, constraints, and the migrations that shaped them. | `kebab-case.md`, named after the table (`users-table.md`). |
| [`decisions/`](./decisions) | Architecture Decision Records (ADRs). Each captures **one** significant decision: the context, the choice, and the trade-offs. | `NNNN-short-title.md`, zero-padded sequential (`0001-…`). |
| [`changelog/`](./changelog) | Per-change notes — what shipped in a meaningful set of work, file-by-file, for reviewers and future readers. | `YYYY-MM-DD-NN-short-title.md`, where `NN` is a two-digit sequence within the day so entries sort chronologically. |

### Conventions

- **Audience:** engineers joining or returning to a module. Assume general
  Laravel / React / Inertia knowledge; explain only what is project-specific.
- **Keep it current:** when a module changes meaningfully, update its module doc in
  the same PR. ADRs are append-only — supersede an old ADR with a new one instead
  of rewriting history.
- **Link, don't duplicate:** reference code paths (`server/app/...`) and other docs
  rather than pasting large code blocks. Short illustrative snippets are fine.
- **Each ADR is immutable once merged.** If a decision is reversed, add a new ADR
  that references and supersedes it.

## Index

### Modules
- [Multi-tenancy](./modules/multi-tenancy.md) — organisation isolation, current-tenant resolution, registration provisioning.
- [Employees](./modules/employees.md) — HR hub: directory, 201 file, career history, lifecycle.
- [User Management](./modules/user-management.md) — accounts, access, archiving, bulk ops.
- [Roles & Permissions](./modules/roles-permissions.md) — RBAC, permission matrix, system-wide authorization.
- [Activity Logs](./modules/activity-logs.md) — read-only audit trail; logging API.
- [Notifications](./modules/notifications.md) — in-app, email & web-push; broadcast & preferences.

### Database
- [Entity Relationship Diagram](./database/erd.md) — **draft** full-system data model (all modules).
- [`organizations` & the tenant column](./database/organizations-table.md) — multi-tenancy schema.
- [`users` table](./database/users-table.md) — identity, profile, account, and soft-delete columns.
- [`roles`, `permissions` & pivots](./database/roles-permissions-tables.md) — RBAC schema.
- [`notifications`, `push_subscriptions` & prefs](./database/notifications-tables.md) — notification schema.
- [`employees` & organisation tables](./database/employees-tables.md) — employee hub, 201 file, departments/positions/schedules.

### Decisions
- [0001 — User identity & management foundation](./decisions/0001-user-identity-and-management.md)
- [0002 — Role-based access control & authorization](./decisions/0002-rbac-authorization.md)
- [0003 — Notification delivery & channels](./decisions/0003-notification-channels.md)
- [0004 — Employee as a record separate from User](./decisions/0004-employee-user-separation.md)
- [0005 — Multi-tenancy: one organisation per registration](./decisions/0005-multi-tenancy.md)

### Changelog
- [2026-06-10 — Profile photos, email verification & toast styling](./changelog/2026-06-10-01-user-profile-photos-verification-toasts.md)
- [2026-06-10 — Custom sidebar scrollbar](./changelog/2026-06-10-02-sidebar-scrollbar.md)
- [2026-06-10 — Self-action toasts & header avatar](./changelog/2026-06-10-03-self-guard-toast-and-header-avatar.md)
- [2026-06-10 — Activity Logs module](./changelog/2026-06-10-04-activity-logs-module.md)
- [2026-06-10 — Roles & Permissions + authorization](./changelog/2026-06-10-05-roles-and-permissions.md)
- [2026-06-10 — Roles row selection & bulk delete](./changelog/2026-06-10-06-roles-bulk-actions.md)
- [2026-06-10 — Notifications module (in-app, email & web push)](./changelog/2026-06-10-07-notifications-module.md)
- [2026-06-10 — Employees module + organisation foundation](./changelog/2026-06-10-08-employees-module.md)
- [2026-06-11 — Multi-tenancy (one organisation per registration)](./changelog/2026-06-11-01-multi-tenancy.md)
