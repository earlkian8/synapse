# SYNAPSE Documentation

Project documentation for the SYNAPSE HR ERP. This folder is the single source of
truth for **how and why** the system is built — complementing the inline code and
commit history rather than repeating them.

## How this folder is organised

| Folder | Purpose | Naming |
| --- | --- | --- |
| [`modules/`](./modules) | One document per functional module (User Management, Recruitment, Performance, …). Describes features, routes, backend + frontend architecture, and how to extend it. | `kebab-case.md`, named after the module (`user-management.md`). |
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
- [Dashboard](./modules/dashboard.md) — the home overview: permission-aware KPIs, hand-drawn charts, an action queue & activity feed.
- [Reports](./modules/reports.md) — decision-support analytics workspace: per-module reports with charts, ML signals & on-demand LLM insights, all exportable.
- [Assistant](./modules/assistant.md) — the floating chat assistant: answers from records read for the asker, acts through 277 permission-scoped tools across 33 modules, holds consequential changes for a Confirm.
- [Multi-tenancy](./modules/multi-tenancy.md) — organisation isolation, current-tenant resolution, registration provisioning.
- [Recruitment](./modules/recruitment.md) — ATS: job postings, candidate pool, hiring pipeline, interviews, hire → employee bridge.
- [Onboarding](./modules/onboarding.md) — template-driven checklists carrying each new hire from day one to productive.
- [Employees](./modules/employees.md) — HR hub: directory, 201 file, career history, lifecycle.
- [Leave Management](./modules/leave.md) — time off: approval inbox, derived balances, leave types.
- [Attendance](./modules/attendance.md) — DTR: punch events, daily records, schedules and the shift roster (in Company Setup); sign-off; geofenced and offline capture; days that close themselves; mobile token API.
- [Performance Management](./modules/performance.md) — weighted KPI evaluations across review periods, with a derived overall score.
- [Training & Development](./modules/training.md) — training programs with a derived lifecycle + scored employee enrollments.
- [Awards & Recognition](./modules/awards.md) — a recognition feed over a typed, colour-coded award catalogue.
- [Events & Meetings](./modules/events.md) — scheduled events/meetings with a derived lifecycle + an invitee roster.
- [Offboarding](./modules/offboarding.md) — structured employee exits: a department-grouped clearance checklist + the separation bridge.
- [Promotion Readiness](./modules/promotion-readiness.md) — an ML score of each employee's readiness for promotion, from their appraisal record.
- [Performance Forecast](./modules/performance-forecast.md) — next-period performance ratings with a range and a confidence, from the shared ML service.
- [Attrition Risk](./modules/attrition-risk.md) — who is likely to leave, with the factors behind each score, from a model trained on attrition surveys.
- [Model graduation](./modules/model-graduation.md) — how each predictive surface moves from the general model to one trained on the organisation's own records.
- [Company Profile (Company Setup)](./modules/company-profile.md) — the tenant's own identity, contact details, logo & statutory employer numbers.
- [Company Setup Wizard](./modules/company-setup-wizard.md) — the guided walk-through a brand-new company gets before its dashboard: a step for every Company Setup screen, each carrying that screen's editors, with suggestions to start from.
- [Product Tour](./modules/product-tour.md) — the first-run walk around the app, offered once to everyone and replayable from Help: a spotlight on each part of the screen their role can use, then where to start.
- [Help Center](./modules/help-center.md) — the user manual in the app: an article for every screen, searchable, with help for the page you are on — read for the reader, so nobody is shown a screen they cannot open.
- [Work Schedule & Holidays (Company Setup)](./modules/work-schedule-holidays.md) — shift patterns + the holiday calendar (holidays aren't charged as leave).
- [Attendance Policies (Company Setup)](./modules/attendance-policies.md) — how a day is judged: presets and typed options, minute buckets, the payroll period summary.
- [Work Locations (Company Setup)](./modules/work-locations.md) — sites drawn on a map, the fences punches are checked against, and who is based where.
- [Departments (Company Setup)](./modules/departments.md) — org-structure config: department hierarchy + positions.
- [User Management](./modules/user-management.md) — accounts, access, archiving, bulk ops.
- [Roles & Permissions](./modules/roles-permissions.md) — RBAC, permission matrix, system-wide authorization.
- [Activity Logs](./modules/activity-logs.md) — read-only audit trail; logging API.
- [Trash Bin](./modules/trash-bin.md) — archived records from every module in one place: restore or delete for good.
- [Notifications](./modules/notifications.md) — in-app, email & web-push; broadcast & preferences.
- [Mobile app](./modules/mobile-app.md) — the employee companion (Expo): clock in and out, attendance, leave, awards, several workspaces.

### Guides
- [Deployment](./deployment.md) — the single Docker image (web app, ML service, queue, scheduler) and Supabase for the database and uploaded files.

### Database
- [Entity Relationship Diagram](./database/erd.md) — the built data model, every table by domain.
- [`organizations` & the tenant column](./database/organizations-table.md) — multi-tenancy schema.
- [`users` table](./database/users-table.md) — identity, profile, account, and soft-delete columns.
- [identity & membership tables](./database/identity-and-membership-tables.md) — workspace membership, invitations, join requests, passkeys, mobile tokens.
- [`roles`, `permissions` & pivots](./database/roles-permissions-tables.md) — RBAC schema.
- [`notifications`, `push_subscriptions` & prefs](./database/notifications-tables.md) — notification schema.
- [`employees` & organisation tables](./database/employees-tables.md) — employee hub, 201 file, departments/positions/schedules.
- [recruitment tables](./database/recruitment-tables.md) — job postings, applicants, applications, interviews.
- [onboarding tables](./database/onboarding-tables.md) — programs, blueprint tasks, cases, checklist tasks.
- [leave tables](./database/leave-tables.md) — leave types, balances (entitlement), requests + approval lifecycle.
- [attendance tables](./database/attendance-tables.md) — punch events (with where and how they were captured), daily records (with minute buckets and flags), attendance policies and work locations.
- [scheduling tables](./database/scheduling-tables.md) — shift day patterns, dated assignments, roster overrides.
- [performance tables](./database/performance-tables.md) — KPI criteria, evaluation periods, evaluations + per-criterion scores.
- [training tables](./database/training-tables.md) — training programs (derived status) + employee enrollments.
- [awards tables](./database/awards-tables.md) — award types (catalogue) + employee awards (recognition feed).
- [events tables](./database/events-tables.md) — events (derived status) + event attendees (invitee roster).
- [offboarding tables](./database/offboarding-tables.md) — clearance templates, exit cases + clearance checklist.
- [work schedule & holiday tables](./database/work-schedule-holidays-tables.md) — work schedules + the holiday calendar.
- [promotion readiness tables](./database/promotion-readiness-tables.md) — assessment runs + per-employee scores.
- [performance forecast tables](./database/performance-forecast-tables.md) — forecast runs + per-employee forecasts.
- [attrition risk tables](./database/attrition-risk-tables.md) — assessment runs + per-employee scores and factors.
- [model graduation tables](./database/model-graduation-tables.md) — each organisation's own trained models.
- [assistant tables](./database/assistant-tables.md) — conversations + messages (with the agent timeline and cards).

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
- [0010 — Attendance (DTR): a punch-event model and a token API for mobile](./decisions/0010-attendance-and-mobile-api.md) (computation partly superseded by 0036)
- [0011 — Benefits Administration: plans + enrollments (superseded by 0019)](./decisions/0011-benefits-administration.md)
- [0012 — Performance Management: weighted KPI evaluations with a derived overall score](./decisions/0012-performance-management.md)
- [0013 — Training & Development: in-module programs with a derived lifecycle](./decisions/0013-training-and-development.md)
- [0014 — Awards & Recognition: a recognition feed over a typed catalogue](./decisions/0014-awards-and-recognition.md)
- [0015 — Events & Meetings: scheduled events with an invitee roster](./decisions/0015-events-and-meetings.md)
- [0016 — Offboarding: structured exits with a clearance checklist](./decisions/0016-offboarding-and-clearance.md)
- [0017 — Predictive Analytics: an external ML inference service (Promotion Readiness)](./decisions/0017-predictive-analytics-and-ml-inference.md) (the model partly superseded by 0045)
- [0018 — Performance Forecast: the second analytics surface on the shared ML service](./decisions/0018-performance-forecasting.md) (the model partly superseded by 0045)
- [0019 — Remove the Payroll and Benefits modules (out of HR-management scope)](./decisions/0019-remove-payroll-and-benefits.md)
- [0020 — Mobile employee companion app & hire-time account provisioning](./decisions/0020-mobile-companion-app.md)
- [0021 — Attrition Risk: the third analytics surface, and an ERP-servable model](./decisions/0021-attrition-risk.md) (superseded by 0030, then 0043)
- [0022 — Mobile multi-workspace sessions (employees of more than one company)](./decisions/0022-mobile-multi-workspace-sessions.md) (superseded by 0023)
- [0023 — Identity vs employment: one user, many organisations](./decisions/0023-identity-and-organization-membership.md)
- [0024 — Agentic recruitment: a full tool surface, scoped by permission](./decisions/0024-agentic-recruitment-and-permission-scoped-tools.md)
- [0025 — Agentic onboarding, and letting the agent chase outstanding work](./decisions/0025-agentic-onboarding-and-chasing-outstanding-work.md)
- [0026 — Self-served identity: people register themselves and join a company](./decisions/0026-self-served-identity-and-workspace-join.md)
- [0027 — Assistant employee retrieval: live tools, not an index, behind a disclosure policy](./decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md)
- [0028 — Appraisal frameworks: the rating model belongs to the tenant](./decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)
- [0029 — Configurable recruitment pipelines, optional résumé, and a Kanban rebuild](./decisions/0029-configurable-recruitment-pipelines.md)
- [0030 — Attrition Risk becomes a frontend-only demo surface](./decisions/0030-attrition-risk-frontend-only.md) (superseded by 0043)
- [0031 — Model graduation panels, embedded per surface](./decisions/0031-model-graduation-frontend-only.md) (superseded by 0046)
- [0032 — Guided company setup before the first dashboard](./decisions/0032-guided-company-setup.md)
- [0033 — Confirming an email address with a code, not a link](./decisions/0033-email-verification-by-code.md)
- [0034 — A company writes its own rules in the setup wizard](./decisions/0034-a-company-writes-its-own-setup.md)
- [0035 — The assistant answers from a retrieved brief; tools are for doing](./decisions/0035-assistant-answers-from-a-retrieved-brief.md)
- [0036 — Attendance is judged in the organisation's local time, on shift-anchored work dates](./decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
- [0037 — Schedules are templates, assignments are dated, and a resolver decides the day's shift](./decisions/0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
- [0038 — Attendance policies: presets and typed options, snapshotted per day; minutes, not money](./decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md)
- [0039 — Attendance requests and period lock; the engine guards the lock](./decisions/0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md) (superseded by 0042)
- [0040 — Punch capture: geofences, device ingestion, and records-for-devices](./decisions/0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md) (its devices superseded by 0054)
- [0041 — Attendance days close themselves](./decisions/0041-attendance-days-close-themselves.md)
- [0042 — No attendance requests or periods; the roster is setup](./decisions/0042-no-attendance-requests-or-periods-the-roster-is-setup.md)
- [0043 — Attrition Risk is real again, trained on the attrition surveys](./decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md)
- [0044 — The setup wizard carries every Company Setup screen](./decisions/0044-the-setup-wizard-carries-every-company-setup-screen.md)
- [0045 — Performance Forecast and Promotion Readiness: models that can be relied on](./decisions/0045-performance-and-promotion-models-that-can-be-relied-on.md)
- [0046 — Model graduation trains on the organisation's own records](./decisions/0046-model-graduation-trains-on-the-organisations-own-records.md)
- [0047 — Workforce list pages share one table kit](./decisions/0047-workforce-list-pages-share-one-table-kit.md)
- [0048 — Talent acquisition and offboarding join the table kit](./decisions/0048-talent-acquisition-and-offboarding-join-the-table-kit.md)
- [0049 — The assistant assumes it will be prompt-injected](./decisions/0049-assistant-prompt-injection-defences.md)
- [0050 — The assistant runs Training, Awards and Events; what notifies people waits for a Confirm](./decisions/0050-assistant-training-awards-and-events.md)
- [0051 — Offboarding and Reports join the assistant; a user is picked from the workspace's members](./decisions/0051-assistant-offboarding-reports-and-workspace-members.md)
- [0052 — The assistant shapes the org structure, but not pay, attendance rules or permanent deletes](./decisions/0052-assistant-departments-without-pay.md)
- [0053 — The assistant sets the company's clock, calendar and attendance rules, and says whom a change reaches](./decisions/0053-assistant-company-profile-schedules-and-attendance-policies.md)
- [0054 — No kiosks or biometric scanners](./decisions/0054-no-kiosks-or-biometric-scanners.md)
- [0055 — The assistant keeps the sites, leave types, award types and performance framework, but never places a fence](./decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md)
- [0056 — The assistant keeps the hiring pipelines and clearance templates, and a kept stage keeps its candidates](./decisions/0056-assistant-recruitment-pipelines-and-clearance-templates.md)
- [0057 — Users, roles, the audit trail and the trash bin join the assistant; nobody hands out access they do not hold](./decisions/0057-assistant-users-roles-activity-and-trash.md)
- [0058 — Attrition Risk, Promotion Readiness and Performance Forecast join the assistant; a score is read, not recomputed, and pay stays out](./decisions/0058-assistant-attrition-promotion-and-forecast.md)
- [0059 — The assistant covers the whole system: the system guide, notifications, app access, employee records, leave self-service and attendance review](./decisions/0059-assistant-covers-the-whole-system.md)
- [0060 — A first-run tour of the app, offered once, for what your role can reach](./decisions/0060-a-first-run-tour-of-the-app.md)
- [0061 — One container image, with Supabase for the database and the files](./decisions/0061-one-container-image-with-supabase-for-data-and-files.md)
- [0062 — A Help Center in the app, written as Markdown and read for the reader](./decisions/0062-a-help-center-read-for-the-reader.md)

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
- [2026-06-13 — Case-insensitive search via Postgres `ILIKE`](./changelog/2026-06-13-03-search-ilike.md)
- [2026-06-13 — Standards-based email verification (Brevo)](./changelog/2026-06-13-04-email-verification.md)
- [2026-06-13 — Trash Bin module](./changelog/2026-06-13-05-trash-bin.md)
- [2026-06-14 — Notification delivery: reliable email, fault-tolerant push](./changelog/2026-06-14-01-notification-delivery-fixes.md)
- [2026-06-14 — Rebrand: NEXO → SYNAPSE](./changelog/2026-06-14-02-rename-nexo-to-synapse.md)
- [2026-06-14 — Public careers pages & richer candidate applications](./changelog/2026-06-14-03-public-careers-and-applications.md)
- [2026-06-15 — Recruitment board: view switch, posting details & a day fix](./changelog/2026-06-15-01-recruitment-board-views-details-and-day-fix.md)
- [2026-06-15 — Recruitment pipeline: table + card grid with stage tabs](./changelog/2026-06-15-02-recruitment-pipeline-table-view.md)
- [2026-06-15 — Attendance module (DTR), mobile-ready](./changelog/2026-06-15-03-attendance-module.md)
- [2026-06-16 — Attendance workspace: tabs + exceptions](./changelog/2026-06-16-01-attendance-workspace-tabs.md)
- [2026-06-16 — Payroll module](./changelog/2026-06-16-02-payroll-module.md)
- [2026-06-16 — Payroll configuration (Company Setup) + payslip fix](./changelog/2026-06-16-03-payroll-config-and-payslip-fix.md)
- [2026-06-17 — Per-employee pay items + manual payslip editing](./changelog/2026-06-17-01-payroll-per-employee-pay-items-and-manual-payslips.md)
- [2026-06-17 — Benefits Administration (with Company Setup)](./changelog/2026-06-17-02-benefits-administration.md)
- [2026-06-17 — Statutory benefit contributions (remittance report)](./changelog/2026-06-17-03-statutory-benefit-contributions.md)
- [2026-06-18 — Performance Management module + KPI/Evaluation Setup](./changelog/2026-06-18-01-performance-management-module.md)
- [2026-06-18 — Training & Development module](./changelog/2026-06-18-02-training-and-development-module.md)
- [2026-06-18 — Awards & Recognition module + Award Types setup](./changelog/2026-06-18-03-awards-and-recognition-module.md)
- [2026-06-18 — Events & Meetings module](./changelog/2026-06-18-04-events-and-meetings-module.md)
- [2026-06-19 — Fix: nested API-resource collections double-wrapped in `data` on Inertia detail pages](./changelog/2026-06-19-01-inertia-nested-resource-data-wrap-fix.md)
- [2026-06-19 — Seeders: complete demo data coverage for every module](./changelog/2026-06-19-02-complete-mock-data-coverage.md)
- [2026-06-19 — Offboarding module](./changelog/2026-06-19-03-offboarding-module.md)
- [2026-06-19 — Company Profile module](./changelog/2026-06-19-04-company-profile-module.md)
- [2026-06-19 — Work Schedule & Holidays module](./changelog/2026-06-19-05-work-schedule-holidays-module.md)
- [2026-06-23 — Promotion Readiness module + ML inference service](./changelog/2026-06-23-01-promotion-readiness-and-ml-inference.md)
- [2026-06-27 — Performance Forecast module](./changelog/2026-06-27-01-performance-forecast-module.md)
- [2026-06-27 — Remove the Payroll and Benefits modules](./changelog/2026-06-27-02-remove-payroll-and-benefits.md)
- [2026-06-27 — Mobile employee companion app](./changelog/2026-06-27-03-mobile-companion-app.md)
- [2026-06-28 — Attrition Risk module](./changelog/2026-06-28-01-attrition-risk-module.md)
- [2026-06-28 — User Management — CSV import, role filter & bulk role assignment](./changelog/2026-06-28-02-user-management-import-and-role-tools.md)
- [2026-06-28 — Mobile multi-workspace sessions](./changelog/2026-06-28-03-mobile-multi-workspace-sessions.md)
- [2026-06-28 — One identity, many organisations (membership refactor)](./changelog/2026-06-28-04-identity-and-organization-membership.md)
- [2026-06-28 — Workspace picker landing (web + mobile)](./changelog/2026-06-28-05-workspace-picker-landing.md)
- [2026-06-28 — Home dashboard: a real overview](./changelog/2026-06-28-06-dashboard-overview.md)
- [2026-06-28 — Reports module (auditing)](./changelog/2026-06-28-07-reports-module.md)
- [2026-06-28 — Reports become decision support (charts + ML + LLM)](./changelog/2026-06-28-08-reports-decision-support.md)
- [2026-06-29 — Recruitment: fit scoring, due dates & decision support](./changelog/2026-06-29-01-recruitment-fit-scoring-and-decision-support.md)
- [2026-06-29 — Recruitment: AI candidate insights](./changelog/2026-06-29-02-recruitment-ai-candidate-insights.md)
- [2026-08-10 — Recruitment: the full agentic tool surface](./changelog/2026-08-10-01-recruitment-agentic-tool-surface.md)
- [2026-08-10 — Onboarding: the full agentic tool surface, and chasing outstanding work](./changelog/2026-08-10-02-onboarding-agentic-tool-surface.md)
- [2026-08-10 — Self-served identity: people register themselves and join a company](./changelog/2026-08-10-03-self-served-identity-and-workspace-join.md)
- [2026-08-11 — Recruitment opens in the middle of the screen](./changelog/2026-08-11-01-recruitment-centred-modals.md)
- [2026-08-11 — Onboarding opens in the middle of the screen too](./changelog/2026-08-11-02-onboarding-centred-modals.md)
- [2026-08-11 — Employees: centred modals, and an assistant that can actually answer](./changelog/2026-08-11-03-employees-modals-and-assistant-retrieval.md)
- [2026-08-11 — The suite is green, and two of the failures were real bugs](./changelog/2026-08-11-04-green-suite-two-real-bugs.md)
- [2026-08-12 — Performance stops being a number out of five](./changelog/2026-08-12-01-appraisal-frameworks-and-tenant-rating-models.md)
- [2026-08-12 — Every page renders, and the empty board stops giving bad advice](./changelog/2026-08-12-02-every-page-renders.md)
- [2026-09-01 — The auth screens catch up to the rest of the product](./changelog/2026-09-01-01-auth-screens-redesign.md)
- [2026-09-01 — Recruitment stops assuming an office hiring process, and the pipeline gets a board](./changelog/2026-09-01-02-generic-recruitment-pipelines-and-kanban-board.md)
- [2026-09-02 — Make Attrition Risk a frontend-only demo surface](./changelog/2026-09-02-01-attrition-risk-frontend-only.md)
- [2026-09-02 — The demo seed catches up with the schema, and ships one login instead of five](./changelog/2026-09-02-02-seed-catches-up-with-the-schema.md)
- [2026-09-02 — The predictive analytics screens stop talking to developers](./changelog/2026-09-02-03-ml-surfaces-stop-talking-to-developers.md)
- [2026-09-02 — Each predictive surface says where its scores came from](./changelog/2026-09-02-04-model-graduation.md)
- [2026-09-09 — The mobile app becomes a white product, like the ERP](./changelog/2026-09-09-01-mobile-white-first-colour-scheme.md)
- [2026-09-09 — The SYNAPSE mark, actually used, at sizes you can see it](./changelog/2026-09-09-02-synapse-mark-across-both-apps.md)
- [2026-09-09 — Attendance opens in the middle of the screen](./changelog/2026-09-09-03-attendance-centred-modals.md)
- [2026-09-09 — A framework's criteria are chosen, not typed](./changelog/2026-09-09-04-criteria-are-chosen-not-typed.md)
- [2026-09-10 — A new company is set up before it is dropped into a dashboard](./changelog/2026-09-10-01-guided-company-setup.md)
- [2026-09-10 — Confirming your email address is a code you type, not a button you click](./changelog/2026-09-10-02-email-verification-by-code.md)
- [2026-09-10 — The setup wizard takes the company's own rules, not just ours](./changelog/2026-09-10-03-a-company-writes-its-own-setup.md)
- [2026-09-10 — A punch says whether it was photographed, and Leave opens in the middle](./changelog/2026-09-10-04-attendance-evidence-and-leave-modals.md)
- [2026-09-10 — The assistant reads the file before it answers](./changelog/2026-09-10-05-assistant-answers-from-a-retrieved-brief.md)
- [2026-09-13 — Attendance keeps the organisation's clock](./changelog/2026-09-13-01-attendance-on-the-organisation-clock.md)
- [2026-09-17 — A schedule is a pattern of days, and the roster says who works it](./changelog/2026-09-17-01-schedules-assignments-and-the-roster.md)
- [2026-09-18 — Each company decides how its days are judged](./changelog/2026-09-18-01-attendance-policies.md)
- [2026-09-18 — People ask for what the clock missed, and a closed period stays closed](./changelog/2026-09-18-02-attendance-requests-and-period-lock.md)
- [2026-09-19 — Punches that can be trusted, and days that close themselves](./changelog/2026-09-19-01-attendance-capture-and-automation.md)
- [2026-09-23 — Attendance without requests or periods, and the roster in Company Setup](./changelog/2026-09-23-01-attendance-without-requests-or-periods.md)
- [2026-09-23 — The mobile entry screens go white, with navy second](./changelog/2026-09-23-02-mobile-entry-screens-white-navy-second.md)
- [2026-09-24 — Attrition Risk, trained on the attrition surveys](./changelog/2026-09-24-01-attrition-risk-trained-on-the-attrition-surveys.md)
- [2026-09-25 — The setup wizard carries every Company Setup screen](./changelog/2026-09-25-01-setup-wizard-carries-every-company-setup-screen.md)
- [2026-09-26 — Performance Forecast and Promotion Readiness, made reliable](./changelog/2026-09-26-01-performance-and-promotion-models-that-can-be-relied-on.md)
- [2026-09-27 — Model graduation, for real — and a panel people can follow](./changelog/2026-09-27-01-model-graduation-trains-on-the-organisations-own-records.md)
- [2026-09-27 — Seven years of workforce history, so model graduation can be used](./changelog/2026-09-27-02-workforce-history-for-model-graduation.md)
- [2026-09-27 — Onboarding reads programs → people → checklist](./changelog/2026-09-27-03-onboarding-programs-people-checklist.md)
- [2026-09-27 — Workforce modules in compact tables](./changelog/2026-09-27-04-workforce-modules-in-compact-tables.md)
- [2026-09-28 — Model graduation in a modal; Talent Acquisition and Offboarding in tables](./changelog/2026-09-28-01-graduation-modal-and-talent-tables.md)
- [2026-09-28 — Assistant: Dashboard and Performance, and defences against prompt injection](./changelog/2026-09-28-02-assistant-dashboard-performance-and-injection-defences.md)
- [2026-09-28 — Assistant: Training, Awards and Events](./changelog/2026-09-28-03-assistant-training-awards-and-events.md)
- [2026-09-28 — Assistant: Offboarding and Reports, and users picked from the workspace](./changelog/2026-09-28-04-assistant-offboarding-reports-and-workspace-members.md)
- [2026-09-28 — Assistant: Departments](./changelog/2026-09-28-05-assistant-departments.md)
- [2026-09-28 — Assistant: Company Profile, Work Schedule & Holidays, Attendance Policies](./changelog/2026-09-28-06-assistant-company-schedules-and-attendance-policies.md)
- [2026-09-29 — No kiosks or biometric scanners](./changelog/2026-09-29-01-no-kiosks-or-biometric-scanners.md)
- [2026-09-29 — Assistant: Locations, Leave Types, Award Types, Performance Framework](./changelog/2026-09-29-02-assistant-locations-leave-and-award-types-and-performance-framework.md)
- [2026-09-29 — Assistant: Recruitment Pipelines and Offboarding Programs; pipelines that can be edited](./changelog/2026-09-29-03-assistant-recruitment-pipelines-and-clearance-templates.md)
- [2026-09-29 — Assistant: Users, Roles & Permissions, Activity Logs and the Trash Bin; nobody hands out access they do not hold](./changelog/2026-09-29-04-assistant-users-roles-activity-and-trash.md)
- [2026-09-29 — Assistant: Attrition Risk, Promotion Readiness and Performance Forecast](./changelog/2026-09-29-05-assistant-attrition-promotion-and-forecast.md)
- [2026-09-29 — Assistant: the system guide, notifications, app access, employee records, leave self-service and attendance review](./changelog/2026-09-29-06-assistant-covers-the-whole-system.md)
- [2026-09-29 — A first-run tour of the app](./changelog/2026-09-29-07-first-run-product-tour.md)
- [2026-10-01 — Supabase for the database and the uploaded files](./changelog/2026-10-01-01-supabase-database-and-storage.md)
- [2026-10-01 — One Docker image for the whole system](./changelog/2026-10-01-02-docker-image.md)
- [2026-10-01 — The docs catch up with the code](./changelog/2026-10-01-03-docs-catch-up.md)
- [2026-10-01 — A Help Center: the user manual, in the app](./changelog/2026-10-01-04-help-center.md)
