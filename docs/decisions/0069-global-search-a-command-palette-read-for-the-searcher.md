# 0069 — Global search: a command palette, read for the person searching

- **Status:** Accepted
- **Date:** 2026-10-07
- **Related:**
  - [0062 — A Help Center read for the reader](./0062-a-help-center-read-for-the-reader.md)
    (the rule that nobody is shown a thing they cannot open, which search follows);
  - [0059 — The assistant covers the whole system](./0059-assistant-covers-the-whole-system.md)
    (`SystemGuide`, whose screens search also finds);
  - [0067 — The assistant is a panel opened from the top bar](./0067-the-assistant-is-a-panel-opened-from-the-top-bar.md)
    (the top bar search sits beside, and the ⌘J shortcut it mirrors);
  - [0047 — Workforce list pages share one table kit](./0047-workforce-list-pages-share-one-table-kit.md)
    (the list pages whose drawers a result opens);
  - module doc: [Global search](../modules/global-search.md).

## Context

The top bar has carried a search field ("Search employees, docs…") and, on narrow
screens, a search icon since the first layout. Neither did anything. Finding a
person meant knowing which module held them, opening it, and searching there; finding
a screen meant reading the sidebar.

What the codebase already had to build on:

1. **Twelve `scopeSearch()` scopes**, driver-aware (`ilike` on Postgres, `like` on
   SQLite), used by every list page and by the assistant, which applies them once per
   word (`matchByTokens`) so "Maria Santos" matches a first *and* a last name.
2. **Two catalogues read for a user**: `SystemGuide` (every screen, with keywords) and
   `HelpCenter` (every article), each leaving out what the person cannot open.
3. **Two kinds of record view.** Job postings, onboarding and offboarding cases,
   appraisals, training programs and events have a page with a hashid address.
   Employees, leave requests, applications, departments, users and roles open only as
   a drawer over their list page, and nothing could open those drawers from an address.
4. **Two traps.** `User` is not tenant-scoped (people belong to several companies), so
   the Users list confines itself with `inCurrentOrganization()`. And
   `OrganizationScope` is a no-op when no tenant is bound, so an unguarded query in
   that state sees every company's rows.

## Decision

**A command palette, opened from the top bar or with ⌘K / Ctrl+K anywhere.** The
field in the bar (and the icon on narrow screens) opens a centred dialog. Typing
searches; results are grouped by kind; ↑ ↓ move, ↵ opens, Esc closes. With nothing
typed it lists the screens the person can open, without a request. The last row offers
"Ask the assistant" with the words already typed, when the assistant is offered.

**One endpoint, read for the searcher.** `GET /search?q=` (`search`) answers JSON. A
`GlobalSearchRequest` accepts 2–80 characters; the controller hands the words to
`App\Support\Search\GlobalSearch`, which asks each **source** it holds:

| Kind | Permission | Matches | Opens |
|---|---|---|---|
| Screens | the screen's own (`SystemGuide`) | title, keywords, tasks | the screen |
| Employees | `employees.view` | name, number, email, phone | their drawer on Employees |
| Applicants | `recruitment.view` | name, email, headline | their latest application, on its posting's board |
| Job postings | `recruitment.view` | title, description, requirements | the posting's board |
| Onboarding | `onboarding.view` | the new hire's name, number | the case |
| Offboarding | `offboarding.view` | the leaver's name, number | the case |
| Leave requests | `leave.view` | the employee, the leave type | its drawer on Leave |
| Appraisals | `performance.view` | the employee, the cycle | the appraisal |
| Training | `training.view` | name, provider | the program |
| Events | `events.view` | title, location | the event |
| Departments | `setup.departments.view` | name, code | its drawer on Departments |
| Users | `users.view` | name, email, employee id, phone | their drawer on User Management |
| Roles | `roles.view` | name, label, description | its drawer on Roles |
| Help | the article's own (`HelpCenter`) | title, keywords, text | the article |

Each source's permission is the one its own route checks, so search never shows
anybody more than the screens do; a kind the person cannot open is not queried at all.

**Matching reuses the models' scopes.** Each record source applies its model's
`scopeSearch()` once per word, ANDed — the same behaviour as the assistant's
`matchByTokens`, now on `GlobalSearch`'s own `matchEveryWord()`. Models that had no
scope gain one: `TrainingProgram`, `Event`, and the employee-keyed records
(`OnboardingCase`, `OffboardingCase`, `LeaveRequest`, `PerformanceEvaluation`), where a
word may match the employee *or* the record's own label, so "maria q3" finds Maria's Q3
appraisal. Free-text reasons (leave, offboarding) are **not** searched: they can hold
medical or personal detail, and a match would reveal it.

**Ranking.** In SQL, before the limit of five: a row whose name column *is* what was
typed comes first, then one whose name column *starts with* the first word, then the
rest in the source's own order (people by surname, events by date, the rest newest
first). Groups appear in a fixed order (screens, people, then records, then help), so
the palette does not reshuffle while somebody types.

**Confinement is explicit.** With no tenant bound the search answers nothing rather
than trust a no-op scope. Users are read through `inCurrentOrganization()`. Archived
(soft-deleted) records are left out, as their lists leave them out by default. No pay,
government ID, bank or address field is read or returned.

**Nothing is logged.** A search changes nothing; like the Help Center it is not
activity-logged. It is throttled (`search`, 90 a minute per person) because each
keystroke fans out into a dozen queries.

**A result opens the record.** A result with a page links to it. A drawer-only record
links to its list page with `?open=<key>` (and, where the list is filtered or paged,
the filters that put it on the page, e.g. `/employees?search=EMP-0007&open=12`). A
shared hook, `useLinkedRecord`, reads `open` from the page's address and opens the row
with the page's own handler, then drops `open` from the address with a client-side
`router.replace`. The drawer stays open, and a reload, the redirect after an action
taken in the drawer, or Back does not open it a second time. Employees, Leave, the
recruitment board, Departments, Users and Roles use it.

## Consequences

- The dead field works, on every screen and every width, and the keyboard reaches it.
- A screen or a record is two keystrokes and a name away, without knowing its module.
- Adding a searchable kind is one source class plus a line in `GlobalSearch`; a test
  checks every source's permission and address against the routes.
- Drawer pages accept `?open=`, so other links (notifications, the assistant) can now
  point at a record rather than its list.
- **Not covered:** the mobile app (its own navigation); attendance records (dozens a
  person — the Attendance screen and the employee's drawer cover them); a history of
  recent searches.
- Every pause in typing (debounced, stale requests cancelled) costs about a dozen
  substring scans (`ilike '%…%'`, which plain indexes cannot serve). That is fine at the
  sizes a company's records reach; if one grows past it, the place to change is the
  sources' queries — e.g. Postgres trigram indexes on the name columns — not the
  palette.
