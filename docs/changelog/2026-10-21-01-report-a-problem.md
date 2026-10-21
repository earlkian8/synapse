# Report a problem, from the ERP and the app

Anyone signed in can now tell the SYNAPSE team when something is broken, slow or looks
wrong, from the web or the phone, with screenshots, and follow the report until it's
settled. Platform admins follow reports up in the admin portal. Their reply comes back
to the sender, who is notified. A report goes to the platform, not to the company. See
[ADR 0096](../decisions/0096-people-report-problems-to-the-synapse-team.md).

## Highlights

- **Report a problem in the ERP.** It's in the top bar's **Help** menu, in the Help
  Center's *Still need help?* and on **Your reports**. The dialog asks what kind of
  problem it is (four cards), for a summary and what happened, and how much it's getting
  in the way. You can add up to three screenshots: choose them, drop them on the box, or
  paste one with Ctrl+V. *Sent with your report* shows exactly what goes with it before
  you send. A note points record mistakes to HR. A click beside a started report doesn't
  throw it away. Once it's sent, you get the reference (`PR-7K2M9Q`, copyable) and a way
  to your reports.
- **Your reports** (`/help/reports`) lists your reports with their status and whether
  the team has replied. A report opens with a Received → Being looked into → Resolved
  tracker, the team's reply, what you wrote and your screenshots. The notification's
  link opens the report it's about.
- **In the app**, Profile → Help has *Report a problem*, a form sheet that also asks
  where in the app the problem is and takes screenshots from your photos, and *Your
  reports*, a list and a report screen. Screenshots open full screen in the app.
- **In the admin portal**, a new **Support** group holds *Problem reports*, with a count
  of new ones. The list has tiles (New, Investigating, Open and blocking work, Resolved
  in 30 days), search, filters for status (Open by default), impact, kind and platform,
  and an impact meter. A report opens in a dialog: what they wrote, the screenshots,
  the sender and their setting, and a follow-up with the status, a reply to the sender
  and an internal note. Overview gains an *open* tile and *Problem reports waiting*.
- **A Help Center article**, *Reporting a problem* (Troubleshooting). The FAQ's last
  section points to it.

## Backend

- `problem_reports` and `problem_report_attachments`
  (`2026_10_21_000000_create_problem_reports_tables`). They are not tenant-scoped, and a
  report is addressed by its reference.
- `Support\ProblemReports\ProblemReports` files every report. `Screenshots` re-encodes
  each image with GD: turned upright, capped at 2,560 px, PNG/WebP to PNG, JPEG to JPEG,
  with no EXIF kept. `MyProblemReports` is the sender's reading for the web and the app.
  `ProblemReportTriage` handles an admin's follow-up, audit and notification.
- `StoreProblemReportRequest` is shared by both clients. The web is forced to `web`, its
  device is the user agent and its page is a path without the query string.
  Screenshots: at most 3, PNG/JPEG/WebP, 8 MB, 8,000 px.
- Routes: `GET`/`POST /help/reports`, `GET`/`POST /api/problem-reports` and
  `GET /api/problem-reports/{reference}`, plus the portal's
  `/api/admin/problem-reports` (list, `counts`, show, `PATCH`, attachment). A
  `problem-reports` rate limit allows 5 in 10 minutes and 30 a day. The web POST answers
  errors as JSON.
- Uploads gain a `problem-reports` folder, which `UploadAccess` opens to the sender
  only, in any workspace. The streaming code moved to `Support\Files\FileStream`, used by
  `FileController` and the portal.
- The admin activity log has a new event, `problem_report_updated`. `/api/admin/overview`
  adds `open_reports` and `new_reports`. Notifications use category `support`.

## Frontend

- ERP: `features/problem-reports/` holds the dialog (mounted once in the layout and
  opened through a launcher), the screenshot field, status pill, tracker and detail
  dialog. The page is `pages/help/reports.tsx`.
- Admin portal: `features/problem-reports/`. `useListParams` takes a default status.
  `apiBlob()` fetches a screenshot with the token, and the CSP allows `blob:` images.
- App: `app/problem-reports/` (`index`, `new`, `[id]`) and `features/problem-reports/`.
  `app.json` sets a photo-library usage string for `expo-image-picker`.

## Notes

- Tests: `ProblemReportsTest` (11) and `AdminProblemReportsTest` (9).
- The app's photo-library string is a native setting, so it reaches phones with the next
  EAS build. The rest of the app's screens are JavaScript.
- Not built: a conversation (one reply per report), assigning to a named admin, merging
  duplicates, alerting admins outside the portal, capturing console or network errors.
