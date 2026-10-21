# Database: problem report tables

The tables behind [problem reports](../modules/problem-reports.md), created by
`2026_10_21_000000_create_problem_reports_tables`
([ADR 0096](../decisions/0096-people-report-problems-to-the-synapse-team.md)). Neither is
tenant-scoped: a report is written to the platform, not to the company. The sender reads
their own by `user_id` in any workspace, and the admin portal reads them all.

## `problem_reports`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Never shown to a sender, so nobody can count the platform's reports. The admin portal addresses reports by it. |
| `reference` | string(12), unique | `PR-` and six characters from `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (no 0/O, 1/I/L). What the sender and the admins call the report, and how the ERP and the app address it. |
| `user_id` | FK → users | Cascade on delete: the report is the sender's personal data. |
| `organization_id` | FK → organizations, nullable | The workspace the sender was in, for context. Null on delete, and when they were in none. |
| `category` | string(20) | `not_working`, `looks_wrong`, `slow` or `other` (`ProblemReport::CATEGORIES`). |
| `impact` | string(20) | `blocked`, `workaround` or `minor` (`ProblemReport::IMPACTS`), as the sender says. |
| `subject` | string(120) | The sender's summary. |
| `description` | text | What happened, up to 5,000 characters. |
| `platform` | string(10) | `web`, `ios` or `android`. The web is always `web`, whatever it sends. |
| `page` | string, nullable | The web: the path the report was sent from, without its query string. The app: the part of the app the sender picked. |
| `app_version` | string(32), nullable | The app's version. Null from the web. |
| `device` | string, nullable | The web: the browser's user agent, read by the server. The app: the phone's system ("iOS 18.1"). |
| `context` | json, nullable | Display facts the client sends: `viewport`, `screen`, `timezone`, `language`, `theme`. Nothing else is accepted. |
| `status` | string(20) | `new` (default), `investigating`, `resolved` or `closed`. Indexed with `created_at`. |
| `reply` | text, nullable | The admins' reply. The sender reads it. |
| `replied_at` | timestamp, nullable | When the reply last changed. Null when there is none. |
| `internal_note` | text, nullable | Read by admins only. Never sent to the ERP or the app. |
| `handled_by` | FK → admins, nullable | The admin who last saved a follow-up. Null on delete. |
| `status_changed_at` | timestamp, nullable | Set when filed and at every status change. |
| `resolved_at` | timestamp, nullable | Set when marked resolved, cleared when the status moves away from it. |
| timestamps | | |

Indexes: `reference` (unique), `(status, created_at)` for the portal's list, and
`(user_id, created_at)` for the sender's.

## `problem_report_attachments`

Up to three screenshots per report, on the private `uploads` disk under
`problem-reports/` ([ADR 0087](../decisions/0087-uploads-are-private-to-their-workspace.md)).
Every image is decoded and re-encoded before it is stored (PNG and WebP to PNG, JPEG to
JPEG at quality 85, turned upright, at most 2,560 px on the long side), so no photo keeps
its location or camera data.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `problem_report_id` | FK → problem_reports | Cascade on delete. |
| `path` | string, unique | `problem-reports/<40 random characters>.png|jpg`. Never sent to the admin portal, which streams by id. |
| `name` | string | The sender's file name, tidied, with the stored extension. |
| `mime` | string(50) | `image/png` or `image/jpeg`. |
| `size` | integer | Bytes, as stored. |
| `width`, `height` | smallint, nullable | Pixels, as stored (at most 2,560). |
| timestamps | | |

A row deleted by the database's cascade leaves its file on the disk; nothing in the
application deletes a report.
