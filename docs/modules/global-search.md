# Global search

The top bar's search, as a **⌘K command palette**: one place to find a person, a
record, a screen or a help article and open it, from any page — **read for the person
searching**, so it never shows anybody a thing their role cannot open.

> Status: **Active** · Endpoint: `GET /search?q=` (`search`)
> Reached from: the top bar's **Search** field (an icon where the bar is narrow), or
> **⌘K** / **Ctrl+K** anywhere in the app
> See [ADR 0069](../decisions/0069-global-search-a-command-palette-read-for-the-searcher.md).

## What a person sees

- **Nothing typed:** *Go to* — every screen in their sidebar, with its section. One
  character filters that list by name, without a request.
- **Two characters or more:** results grouped by kind, in a fixed order, at most five
  each, closest match first. The words that matched are picked out in the brand teal.
  The last row is **Ask the assistant: “…”** when the assistant is offered to them; it
  opens the panel with the words typed, never sent.
- **Keys:** ↑ ↓ move (wrapping), ↵ opens, Esc closes. Hovering moves the highlight;
  clicking opens. ↵ pressed before the answer to what was typed has arrived opens that
  answer's best match when it lands — never a row left from the previous search.
- **States:** a spinner in the field while a search runs (the previous results stay,
  dimmed); *Nothing you can open matches “…”*; a plain error for a lapsed session,
  too many searches (429) or anything else.

The palette is a Radix dialog: `combobox` input, `listbox` of `option`s grouped with
`group`/headings, `aria-activedescendant` for the highlight. It sits high on wide
screens and at the top on phones.

## What it finds

| Group | Permission | Matches | Opens |
| --- | --- | --- | --- |
| Screens | the screen's own (`SystemGuide`) | title, keywords, tasks | the screen |
| Employees | `employees.view` | name, number, email, phone | `/employees?search=<no>&open=<id>` |
| Applicants | `recruitment.view` | name, email, headline | `/recruitment/<posting>?open=<application>` (latest application) |
| Job postings | `recruitment.view` | title, description, requirements | `/recruitment/<posting>` |
| Onboarding | `onboarding.view` | the new hire | `/onboarding/<case>` |
| Offboarding | `offboarding.view` | the leaver | `/offboarding/<case>` |
| Leave requests | `leave.view` | the employee, the leave type | `/leave?status=all&search=<no>&open=<hashid>` |
| Appraisals | `performance.view` | the employee, the cycle | `/performance/<evaluation>` |
| Training | `training.view` | name, provider | `/training/<program>` |
| Events | `events.view` | title, location | `/events/<event>` |
| Departments | `setup.departments.view` | name, code | `/setup/departments?open=<id>` |
| Users | `users.view` | name, email, employee id, phone | `/system/users?search=<email>&open=<id>` |
| Roles | `roles.view` | name, label, description | `/system/roles?search=<name>&open=<id>` |
| Help | the article's own (`HelpCenter`) | title, keywords, text | `/help/<category>/<article>` |

**Matching.** Each word typed must match (ANDed); each record source applies its model's
`scopeSearch()` once per word, so "maria santos" finds Maria Santos and "maria q3"
finds Maria's Q3 appraisal. A "word" with no letter or digit (`%%`) is dropped. At most
six words count.

**Ranking.** In SQL, before the limit: a row whose rank column *is* what was typed,
then one whose rank column *starts with* the first word, then the rest in the source's
own order (people by surname, events by date, the rest newest first).

**Never searched or shown:** archived (soft-deleted) records, and the leave requests and
appraisals of an archived employee (archiving does not cascade, but the person is not
shown anywhere); the reason given for leave or for leaving; pay, government ID, bank and
address fields.

## Opening a record that lives in a drawer

Employees, leave requests, applications, departments, users and roles open as a drawer
over their list. Their pages call `useLinkedRecord(rows, keyOf, open)`
(`resources/js/hooks/use-linked-record.ts`): when the address carries `?open=<key>`
and the row is on the page, the page's own handler opens it, and `open` is then dropped
from the address with a client-side `router.replace` — so a reload, the redirect after
an action in the drawer, or Back does not open it again. Where the list is filtered or
paged, the link carries the filter that puts the row on the page (`search=`,
`status=all`).

Any link may use this — a notification, for example — not only search.

## Security

- Every record source's permission is the one its route checks; a test resolves every
  result's link to its route and asserts the route's `can:` middleware is that
  permission.
- With no tenant bound, `GlobalSearch` answers nothing: `OrganizationScope` is a no-op
  then.
- Users are not tenant-scoped (one person, several companies): `UserSource` reads them
  through `inCurrentOrganization()`, as the Users list does.
- Throttled by the `search` limiter, 90 a minute per person. The palette waits 180 ms
  after a keystroke and aborts superseded requests.
- `/search` answers errors as JSON (401, 422, 429) — `bootstrap/app.php`'s
  `shouldRenderJsonWhen` covers it as well as `api/*`.
- Reading changes nothing, so search is not activity-logged.

## Code

- **Backend:** `App\Http\Controllers\SearchController` (invokable) +
  `App\Http\Requests\GlobalSearchRequest` (`q`: 2–80 characters) →
  `App\Support\Search\GlobalSearch` (`search()`, `words()`, `matchEveryWord()`,
  `escapeLike()`), the `SearchSource` contract, `SearchResult`, and `RecordSource`
  (permission, query, rank columns, order, `toResult`). One class per kind in
  `App\Support\Search\Sources\`.
- **Model scopes added:** `scopeSearch()` on `TrainingProgram`, `Event`,
  `OnboardingCase`, `OffboardingCase`, `LeaveRequest` (employee or type) and
  `PerformanceEvaluation` (employee or cycle).
- **Frontend:** `resources/js/features/global-search/` — `types.ts`, `api.ts`
  (`searchEverything`, `SearchError`), `use-global-search.ts` (debounce, abort, derived
  loading), `components/search-palette.tsx`, `components/search-trigger.tsx` (rendered
  by `app-sidebar-header.tsx`, owns ⌘K).
- **Help:** *Searching SYNAPSE* (`getting-started/searching-synapse`).
- **Tests:** `tests/Feature/Search/GlobalSearchTest.php` (endpoint, matching, ranking,
  isolation, screens, help) and `GlobalSearchSourcesTest.php` (every kind, permission
  per kind, link ↔ route permission, leave reasons, users across companies, an
  offboarding case with no last day, an archived employee's leave and appraisals).

## Adding a kind

Write a `RecordSource` (its permission must be its route's `can:` ability), give the
model a `scopeSearch()` if it has none, add the class to `GlobalSearch::SOURCES` in the
place its group should appear, add its icon to `KIND_ICONS` and its key to
`SearchGroupKey`, and add it to `GS_RECORD_KINDS` in the sources test. If it opens in a
drawer, call `useLinkedRecord` on its list page.

## Not covered

The mobile app; attendance records (dozens per person — the Attendance screen and the
employee's drawer cover them); recent searches.
