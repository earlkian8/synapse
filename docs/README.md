# SYNAPSE Documentation

Project documentation for the SYNAPSE HR ERP. This folder is the single source of
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
- [Recruitment](./modules/recruitment.md) — ATS: job postings, candidate pool, hiring pipeline, interviews, hire → employee bridge.
- [Onboarding](./modules/onboarding.md) — template-driven checklists carrying each new hire from day one to productive.
- [Employees](./modules/employees.md) — HR hub: directory, 201 file, career history, lifecycle.
- [Leave Management](./modules/leave.md) — time off: approval inbox, derived balances, leave types.
- [Attendance](./modules/attendance.md) — DTR: punch events, daily records, schedules; mobile token API.
- [Payroll](./modules/payroll.md) — pay runs & payslips from salary + attendance; per-employee pay items + manual editing.
- [Benefits Administration](./modules/benefits.md) — benefit plans (HMO, insurance, retirement, wellness) + employee enrollments.
- [Performance Management](./modules/performance.md) — weighted KPI evaluations across review periods, with a derived overall score.
- [Training & Development](./modules/training.md) — training programs with a derived lifecycle + scored employee enrollments.
- [Departments (Company Setup)](./modules/departments.md) — org-structure config: department hierarchy + positions.
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
- [recruitment tables](./database/recruitment-tables.md) — job postings, applicants, applications, interviews.
- [onboarding tables](./database/onboarding-tables.md) — programs, blueprint tasks, cases, checklist tasks.
- [leave tables](./database/leave-tables.md) — leave types, balances (entitlement), requests + approval lifecycle.
- [attendance tables](./database/attendance-tables.md) — punch events, daily records, import batches.
- [payroll tables](./database/payroll-tables.md) — periods, payslips, earning/deduction lines, allowance/deduction types.
- [benefits tables](./database/benefits-tables.md) — benefit plans (catalogue) + employee enrollments.
- [performance tables](./database/performance-tables.md) — KPI criteria, evaluation periods, evaluations + per-criterion scores.
- [training tables](./database/training-tables.md) — training programs (derived status) + employee enrollments.

### Decisions
- [0001 — User identity & management foundation](./decisions/0001-user-identity-and-management.md)
- [0002 — Role-based access control & authorization](./decisions/0002-rbac-authorization.md)
- [0003 — Notification delivery & channels](./decisions/0003-notification-channels.md)
- [0004 — Employee as a record separate from User](./decisions/0004-employee-user-separation.md)
- [0005 — Multi-tenancy: one organisation per registration](./decisions/0005-multi-tenancy.md)
- [0006 — Recruitment as an ATS, with a hire → employee bridge](./decisions/0006-recruitment-ats-and-hire-bridge.md)
- [0007 — Onboarding as a template-driven hire → productive bridge](./decisions/0007-onboarding-template-bridge.md)
- [0008 — Company Setup: managing the org structure (departments & positions)](./decisions/0008-company-setup-org-structure.md)
- [0009 — Leave management: an approval workflow with derived balances](./decisions/0009-leave-management.md)
- [0010 — Attendance (DTR): a punch-event model and a token API for mobile](./decisions/0010-attendance-and-mobile-api.md)
- [0011 — Benefits Administration: plans + enrollments, not a contribution snapshot](./decisions/0011-benefits-administration.md)
- [0012 — Performance Management: weighted KPI evaluations with a derived overall score](./decisions/0012-performance-management.md)
- [0013 — Training & Development: in-module programs with a derived lifecycle](./decisions/0013-training-and-development.md)

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
- [2026-06-11 — Recruitment module (applicant tracking + hire bridge)](./changelog/2026-06-11-02-recruitment-module.md)
- [2026-06-11 — Onboarding module (template-driven hire → productive bridge)](./changelog/2026-06-11-03-onboarding-module.md)
- [2026-06-11 — Departments module (Company Setup: org structure)](./changelog/2026-06-11-04-departments-module.md)
- [2026-06-11 — Leave Management module (approval inbox + derived balances)](./changelog/2026-06-11-05-leave-management-module.md)
- [2026-06-12 — Employee profile photos everywhere](./changelog/2026-06-12-01-employee-profile-photos.md)
- [2026-06-12 — Agentic employee assistant (Gemini)](./changelog/2026-06-12-02-employee-agentic-assistant.md)
- [2026-06-13 — Assistant goes org-wide (all HR modules)](./changelog/2026-06-13-01-assistant-all-modules.md)
- [2026-06-13 — Premium assistant: conversations, streaming & markdown](./changelog/2026-06-13-02-assistant-premium-chat.md)
