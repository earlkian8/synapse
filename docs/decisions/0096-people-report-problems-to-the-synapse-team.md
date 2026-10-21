# 0096 — People report problems to the SYNAPSE team

- **Status:** Accepted
- **Date:** 2026-10-21
- **Related:**
  - [0062 — A Help Center read for the reader](./0062-a-help-center-read-for-the-reader.md)
    (where reporting sits on the web);
  - [0085 — A platform admin portal](./0085-a-platform-admin-portal.md) (where reports are
    followed up, and its audit log);
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md)
    (the disk screenshots live on);
  - [0088 — The app opens its modules from Services](./0088-the-app-opens-its-modules-from-services.md);
  - module docs: [Problem reports](../modules/problem-reports.md),
    [Admin portal](../modules/admin-portal.md), [Help Center](../modules/help-center.md),
    [Mobile app](../modules/mobile-app.md).

## Context

When something in SYNAPSE broke, the people using it had nowhere to say so. The Help
Center's last advice was "ask whoever manages SYNAPSE in your company", but a company's
HR can't fix a page that won't load. The people who can — the platform admins — had a
portal that watched organisations and accounts, but nothing told them what was going
wrong for the people inside them.

## Decision

### A report goes to the platform, not to the company

A **problem report** is written by one person to the SYNAPSE team. The
`problem_reports` table is not tenant-scoped. It records the workspace the sender was in
(`organization_id`) for context, but nobody in that workspace reads the report, and the
sender reads their own from any workspace. The workspace's activity log only notes that a
report was sent, with its reference and never its words, so the "user-facing mutations
are logged" rule holds without exposing the report to HR.

Reports are addressed by a **reference** (`PR-7K2M9Q`), never by id, wherever a sender
can see them. Sequential ids would tell anyone how many reports the platform has. The
admin portal, which sees them all anyway, uses ids, like its other lists.

### One way in, from the web and the app

`Support\ProblemReports\ProblemReports::file()` files every report. The web's dialog
(`POST /help/reports`) and the app's form (`POST /api/problem-reports`) both call it
through one `StoreProblemReportRequest`.

- **The web is always `web`.** The request overwrites `platform` and reads `device` from
  the user agent, so a browser can't pose as the app. The page is kept as a path only. A
  query string can carry a search for a person's name, which a report has no need of.
- **The app says where.** A phone has no URL worth sending, so the form asks which part
  of the app it is (the five tabs, every service the person may open from the Services
  catalogue, Search, signing in). It sends the app's version and the system ("iOS 18.1").
- **What else is sent is shown before sending.** The web dialog lists the name, the
  workspace, the page, the browser, the window, the time zone and the theme under *Sent
  with your report*. The app's form says it in a line. `context` accepts those five keys
  and nothing else.
- **Throttled per person**, to 5 in 10 minutes and 30 a day (`throttle:problem-reports`).

The dialog submits with `fetch`, not an Inertia visit. It sits over whatever page the
person was on, and that page (its filters, its scroll, a half-filled form) must be left
as it was. `bootstrap/app.php` renders that one POST's errors as JSON.

### Screenshots are pixels only

Up to three images (PNG, JPEG or WebP, 8 MB each, at most 8,000 px a side), stored on the
private `uploads` disk under `problem-reports/`. `Screenshots` decodes each one with GD and
encodes it again. It turns a photo upright by its EXIF orientation first, scales anything
over 2,560 px down, and keeps PNG and WebP as PNG (lossless, so text stays sharp) and
JPEG as JPEG. The stored file carries no location or camera data, and a file that only
claims to be an image is refused, not stored.

The sender opens their screenshots through the ordinary file route. `UploadAccess`
allows the `problem-reports` folder to the sender alone, from any workspace, before its
tenant check. A colleague in the same workspace gets the same 404 as anyone else. Admins
get them through `GET /api/admin/problem-reports/{id}/attachments/{id}`, which reads the
admin's token. The portal fetches the image and shows it from an object URL, so no
address to it is ever handed out. The streaming code moved out of `FileController` into
`Support\Files\FileStream`, so both routes serve files the same way.

### Every admin follows reports up

Reports are a portal screen for **every admin**, not only super admins. Following up is
the everyday work, while admin accounts and the log stay with super admins. Monitoring
stays read-only. This is the one place an admin writes, and it writes to the report
only, never to an organisation or an account.

A follow-up (`ProblemReportTriage`) sets three things: the **status** (New ·
Investigating · Resolved · Closed), a **reply** the sender reads, and an **internal
note** only admins read. Every change is recorded as `problem_report_updated` in the
admin activity log, with the status change and whether a reply went out. A save that
changes nothing records nothing.

The sender is notified (`Notifier::toUser`, category `support`, linking to
`/help/reports?report=PR-…`) when the report moves on (Investigating, Resolved, Closed)
or the reply changes. A note between admins, or a report put back to New, tells them
nothing. The notification goes to the bell and, by their own choice, email and web push.
The app has no push channel of its own (see the Notifications module), so a phone shows
the reply when the report is opened.

The sender is shown statuses in their own words: Received · Being looked into ·
Resolved · Closed.

### Where it is

| Surface | Entry |
|---|---|
| ERP | Top bar **Help** → *Report a problem* and *Your reports*; the Help Center's *Still need help?* footer; the **Your reports** page (`/help/reports`, breadcrumb Help Center › Your reports) with its own *Report a problem* button |
| App | **Profile → Help** → *Report a problem* (a form sheet) and *Your reports* (list and detail). Not a Service: reporting is about the app, not one of one's modules |
| Admin portal | A **Support** group in the sidebar: *Problem reports*, with a count of new ones. Overview gains an *open* tile and *Problem reports waiting* |

The portal never polls for the count. A request keeps an admin's session alive (ADR
0085), so the badge is read when the sidebar mounts and again after every save.

## Consequences

- Data Export leaves `problem_reports` out (`DataExportCatalogue::EXCLUDED`): it holds an
  `organization_id`, but the reports are not the company's records.
- The Help Center has a 71st article, *Reporting a problem* (Troubleshooting), and the
  FAQ's last section points to it.
- `mobile/app.json` now sets a photo-library usage string for `expo-image-picker`
  (`photosPermission`). It was `false` because nothing read the library before. The
  system picker needs no permission on current iOS and Android, but App Store review
  expects the string. It needs a new native build.
- The admin portal's CSP allows `blob:` images, for the screenshots.
- A user deleted outright takes their reports with them (cascade). Their screenshot
  files stay on the disk, because nothing in the application deletes a report or a user
  outright.

## Not built

- A conversation. A report has one reply, which an admin can rewrite. The sender can't
  answer it or add to the report. A new problem is a new report.
- Assigning a report to a named admin, or merging duplicates.
- Telling admins about a new report by email or push. They see it in the portal.
- Recording the browser console or the last network errors with a report.
