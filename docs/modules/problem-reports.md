# Module: Problem reports

> Status: **Active** · ERP: `/help/reports` · App: Profile → Help · Admin portal:
> Support → Problem reports · Decision:
> [ADR 0096](../decisions/0096-people-report-problems-to-the-synapse-team.md)

Anyone signed in to the ERP or the app can tell the SYNAPSE team (the people who run the
platform) that something is broken, slow or looks wrong, and follow the report until it's
settled. The platform admins follow reports up in the admin portal. A report goes to the
platform, not to the company: HR never reads it.

---

## 1. Feature overview

| Screen | Who | What it shows / does |
| --- | --- | --- |
| **Report a problem** (ERP dialog) | everyone signed in | Kind (four cards), summary, what happened, how much it gets in the way, up to three screenshots (choose, drop or paste with Ctrl+V), *Sent with your report* (what goes with it, shown before sending), a note that record mistakes go to HR. Once sent: the reference (copyable) and *View your reports*. A click outside a started report doesn't close it. |
| **Your reports** (`/help/reports`) | everyone signed in | One's own reports, newest first (up to 100): summary, reference, *Replied*, kind, sent, status. A report opens in a dialog: a Received → Being looked into → Resolved tracker (Closed shown as such), the team's reply, what was written, screenshots, details. `?report=PR-…` opens one (the notification's link). |
| **Report a problem** (app form sheet) | everyone signed in | The same fields, plus *Where in the app?* (a sheet listing the tabs, every service the person may open, Search, signing in). Screenshots from the photo library. Cancelling a started report asks first. |
| **Your reports** (app) | everyone signed in | The list with status pills, and a report screen with the tracker, the reply, what was written, the screenshots (opened full screen in the app) and details. |
| **Problem reports** (admin portal) | every admin | Tiles: New, Investigating, Open and blocking work, Resolved in 30 days. Search (reference, summary, sender, organisation), status (Open by default), impact, kind, platform; sort by impact or sent. A report dialog: what they reported, the screenshots, *Sender and setting* (sender, organisation, sent, platform and version, page or part of the app, window, time zone, language, theme, browser or device), and **Follow-up**: status, reply to the sender, internal note. |

## 2. Statuses

| Status | Admins see | The sender sees | Sender told? |
| --- | --- | --- | --- |
| `new` | New | Received | — (on filing) / no, when put back |
| `investigating` | Investigating | Being looked into | yes |
| `resolved` | Resolved | Resolved | yes (`resolved_at` set) |
| `closed` | Closed | Closed | yes |

Changing the reply also tells the sender, with the reply as the notification's body. A
note-only change tells them nothing. Notifications use `Notifier::toUser`, category
`support`, and link to `/help/reports?report=PR-…`.

## 3. Rules

- **Reference, not id.** Senders only ever see and address `PR-XXXXXX` (case forgiven in
  addresses). Admins use ids.
- **Own reports only.** The ERP and the API read by `user_id`. Another person's reference
  is a 404. The internal note and the handling admin are never sent to a sender.
- **The web is `web`.** Its `platform` is forced, its `device` is the user agent, and its
  `page` is a path without the query string. The app must say `ios` or `android`.
- **Screenshots**: at most 3; PNG, JPEG or WebP; 8 MB; 8,000 px a side. Re-encoded,
  turned upright and capped at 2,560 px (see the table doc). Readable by the sender
  (`UploadAccess`, any workspace) and by admins (the portal's attachment route), and by
  nobody else.
- **Throttle**: 5 reports in 10 minutes and 30 a day per person.
- **Logged**: the workspace's activity log (`support`, `reported`) gets only the
  reference. The admin log gets every follow-up (`problem_report_updated`).

## 4. Endpoints

| Method | URI | What | Gate |
| --- | --- | --- | --- |
| GET | `/help/reports` | Your reports (Inertia `help/reports`, props `reports`, `focus`) | `auth`, `verified` |
| POST | `/help/reports` | File a report (multipart, JSON 201) | + `throttle:problem-reports` |
| GET | `/api/problem-reports` | One's reports | `auth:sanctum` |
| POST | `/api/problem-reports` | File a report (multipart, 201) | + `throttle:problem-reports` |
| GET | `/api/problem-reports/{reference}` | One report | `auth:sanctum` |
| GET | `/api/admin/problem-reports` | The list, `stats`, `filters` | admin session, password current |
| GET | `/api/admin/problem-reports/counts` | `{new, open}` for the sidebar | same |
| GET | `/api/admin/problem-reports/{id}` | One report, in full | same |
| PATCH | `/api/admin/problem-reports/{id}` | Follow up: `status`, `reply`, `internal_note` | same, `throttle:60,1` |
| GET | `/api/admin/problem-reports/{id}/attachments/{id}` | A screenshot, streamed | same |

`/api/admin/overview` also returns `stats.open_reports`, `stats.new_reports` and the five
newest `open_reports`.

## 5. Code

| Concern | Where |
| --- | --- |
| Models | `app/Models/ProblemReport.php` (categories, impacts, statuses, `search` and `open` scopes), `ProblemReportAttachment.php` |
| Filing, the sender's reading, follow-up | `app/Support/ProblemReports/` (`ProblemReports`, `Screenshots`, `MyProblemReports`, `ProblemReportTriage`) |
| Streaming a stored file | `app/Support/Files/FileStream.php` (shared with `FileController`) |
| Controllers | `Support\ProblemReportController` (web), `Api\ProblemReportController`, `Admin\ProblemReportController` |
| Requests, query, resource | `Requests\Support\StoreProblemReportRequest`, `Requests\Admin\UpdateProblemReportRequest`, `Queries\Admin\ProblemReportsIndexQuery`, `Resources\Admin\ProblemReportResource` |
| ERP | `resources/js/features/problem-reports/` (dialog, launcher, status pill, tracker, detail dialog), `pages/help/reports.tsx`; entries in `product-tour/components/help-menu.tsx` and `help-center/components/still-need-help.tsx`; the dialog mounts in `layouts/app/app-sidebar-layout.tsx` |
| App | `mobile/app/problem-reports/` (`index`, `new`, `[id]`), `mobile/features/problem-reports/`; rows in Profile → Help |
| Admin portal | `admin/src/features/problem-reports/`; the Support group in `components/navigation.ts`; Overview |
| Help | `resources/help/troubleshooting/reporting-a-problem.md` |
| Tests | `tests/Feature/Support/ProblemReportsTest.php`, `tests/Feature/Admin/AdminProblemReportsTest.php` |

Schema: [problem report tables](../database/problem-reports-tables.md).

## 6. Out of scope

A conversation (the sender can't answer a reply), assigning to a named admin, merging
duplicates, notifying admins outside the portal, and capturing console or network errors.
See the ADR.
