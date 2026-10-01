# Help Center

SYNAPSE's user manual, inside the app: a step-by-step article for every screen and
everyday task, grouped the way the sidebar is, searchable, and **read for the person
reading it** — an article about a screen they cannot open is never listed, searched,
linked or served to them.

> Status: **Active** · Route prefix: `/help`
> Reached from: the top bar → Help (the question mark) → *Help Center* or *Help for this
> page*; the app footer's *Docs*; the assistant's `find_help`
> See [ADR 0062](../decisions/0062-a-help-center-read-for-the-reader.md).

## Why it exists

The [product tour](./product-tour.md) shows somebody around once, in a minute. The
Setup Guide configures a company. Neither answers "how do I correct a forgotten
clock-out?" or "why was I marked late?" six weeks later. The assistant can, but it is
not offered to every role (Staff do not get it) and every question spends model quota.
The Help Center is the answer that is always there, for everybody, at no cost per read.

## What a reader sees

| Page | Address | What it holds |
| --- | --- | --- |
| **Home** | `/help` | The navy hero with the search, **Start here** (the reader's featured articles), **Browse by topic** (a card per topic with its first three articles), and **Still need help?** |
| **Topic** | `/help/{category}` | The topic's description and its articles. |
| **Article** | `/help/{category}/{article}` | The article, with the topics down the left, **On this page** on the right (folded into the top of the article below `xl`), related articles, previous / next in reading order, and **Still need help?** |
| **Search** | `/help/search?q=` | Results that refresh as you type, each with the line that matched, the searched-for words in bold. An empty search offers the topics; no match offers the topics and the help panel. |
| **Help for this page** | `/help/for?path=` | Redirects to the article that explains the given page, or to the home. |

On a phone the topics sidebar is a **Browse topics** panel. `/` focuses the search from
anywhere on a help page.

**Still need help?** offers: *Ask the assistant* (only when it is offered to the
reader — it opens with "About “<article>”: " typed, never sent), *Take the tour*, the
*Setup Guide* (for `setup.company.manage`), and *Common questions* (the FAQ).

## The content

54 articles in 11 topics, written for the reader (second person, the screen's own
labels in bold, numbered steps for procedures):

| Topic | Articles |
| --- | --- |
| Getting started | Welcome, Finding your way around, Your dashboard, Setting up your company, Inviting your people |
| Your account | Signing in and out, Profile and appearance, Password / two-factor / passkeys, Notifications, More than one company |
| Talent acquisition | Job postings, Candidates and the pipeline, Interviews and hiring, The careers page, Onboarding new hires |
| Workforce | Employee records, Reviewing attendance, Correcting and signing off attendance, Clocking in and out, Managing leave, Filing your own leave, Appraisals, Training, Awards, Events |
| Offboarding | Offboarding an employee |
| Analytics & AI | Attrition risk, Performance forecast, Promotion readiness, Training a model on your own records, Reports |
| Company setup | Company profile, Departments and positions, Schedules and holidays, Shift roster, Attendance policies, Work locations, Leave types, Award types, Performance framework, Recruitment pipelines, Onboarding programs, Offboarding programs |
| Administration | User accounts, Roles and permissions, Activity logs, Trash bin, Sending announcements |
| The assistant | Meet the assistant, What it will and will not do |
| The mobile app | Joining your company, Clocking in from your phone, Leave / awards / profile in the app |
| Troubleshooting | Frequently asked questions |

Bodies are Markdown under `server/resources/help/<category>/<slug>.md`. Besides
GitHub-flavoured Markdown they use **callouts** — a blockquote opening with `[!NOTE]`,
`[!TIP]`, `[!IMPORTANT]` or `[!WARNING]` — and link to other articles as
`/help/<category>/<slug>` and to screens by their path. No images: a screenshot goes
stale the first time a screen changes.

## Who reads what

Each catalogue entry's `any` holds the permissions of the screen it explains (empty
means everybody); an article is visible when the reader holds any of them. That one
filter drives everything:

- the topics and their counts, the featured articles, the sidebar and the search;
- the article routes — one the reader may not open answers **404**, exactly like one
  that does not exist or sits under the wrong topic;
- **related reading** and the **previous / next** pager skip what the reader may not
  open;
- **links in a body** to an article the reader may not open keep their words and lose
  their address (`HelpCenter::article()` rewrites them on the way out);
- **Help for this page** only ever lands on an article the reader may open.

The two articles about the assistant use `AssistantAccess::PERMISSIONS`, the same rule
that decides whether the assistant is offered at all.

## Search

`HelpCenter::search()` splits the query into words (dropping stop words and single
letters) and scores every visible article: a word in the title 6, in the keywords 4,
in the summary 3, in the body 1, plus 12 for the whole phrase in the title, 8 for a
keyword phrase and 2 for the phrase in the body. An article must match **every** word;
when none does, articles matching **any** word are returned and `partial` says so. The
snippet is ~200 characters of the body around the first match, or the summary when only
the title or keywords matched. Queries are capped at 120 characters.

## Help for this page

Each entry may list `screens`: `/x` is that page, `/x/*` the pages under it.
`HelpCenter::forPath()` ranks, for the reader's visible articles: the page itself, then
a `/*` entry it falls under, then any entry it falls under — the most specific wins. So
`/recruitment` is *Job postings*, `/recruitment/{posting}` is *Candidates and the
pipeline*, and `/setup/wizard/departments` is *Setting up your company*.

## Backend

- **`App\Support\Help\HelpCenter`** — the catalogue (`CATEGORIES`, `ARTICLES`) and
  everything read from it: `articles()`, `categories()`, `category()`, `article()`,
  `search()`, `forPath()`, `href()`, `path()`, `body()` (memoised per request with
  `once()`). Reading time is words ÷ 200.
- **`Help\HelpCenterController`** — `index`, `search`, `forPage`, `category`, `show`.
  Thin; 404 when `HelpCenter` returns nothing.
- **Requests** — `Help\HelpSearchRequest` (`q` ≤ 120) and `Help\HelpForPageRequest`
  (`path` must be an app path: starts with `/`, not `//` or `/\`).
- **Routes** — `routes/help.php`, under `auth` + `verified`, named `help.*`. No
  permission on the routes: the filter is per article. Literal routes (`search`, `for`)
  precede the `{category}` wildcard.
- **`App\Services\Assistant\AssistantAccess`** — who is offered the assistant
  (moved from the client). Shared as `auth.assistant` by `HandleInertiaRequests`.
- **Not activity-logged** — reading the manual changes nothing.
- **No table.** The manual is code and content, versioned with the app.

## Frontend

`resources/js/features/help-center/`:

- `types.ts`, `routes.ts`, `icons.tsx` (`CategoryIcon`, the sidebar's icons per topic);
- `lib/markdown.ts` — `remarkHelp` (heading anchors and callouts) and `headingsOf()`,
  which hand out anchors in the same order from the same slugger, so "On this page"
  and the rendered headings always agree; `lib/highlight.ts`;
- `use-help-search.ts` (submit on Enter; live, debounced partial reloads on the
  results page) and `use-active-heading.ts` (which section is being read);
- components: `help-hero`, `help-search-box`, `category-card`, `article-list`,
  `article-body` (react-markdown + remark-gfm, the app's type scale, in-app links as
  Inertia visits, external links in a new tab, no images), `article-toc`,
  `article-pager`, `help-nav` / `help-nav-sheet`, `help-shell`, `highlighted`,
  `still-need-help`.

Pages: `pages/help/{index,category,article,search}.tsx`.

Around it:

- **Help menu** (`features/product-tour/components/help-menu.tsx`): *Help Center* and
  *Help for this page* (hidden inside the Help Center) above the tour and the Setup
  Guide. The tour's Help stop mentions the Help Center.
- **Assistant**: `features/assistant/launcher.ts` lets any page open the assistant with
  a question typed; the launcher is gated on `auth.assistant`.
- **App footer**: *Docs* links to `/help`.
- **App shell**: the content column is `overflow-x-clip` (was `overflow-x-hidden`,
  which made it a scroll container and silently disabled every `position: sticky`
  inside it — the top bar included).

## The assistant

- `find_help` returns the matching screens as before, then up to two **Help Center**
  cards (title, summary, address) — only when an article matches every word of the
  question, and only articles the asker may read. The guidance tells the model to link
  them.
- `SystemGuide` has a `help-center` entry ("where is the manual?").

## Writing an article

1. Add the entry to `HelpCenter::ARTICLES` in reading order: `category`, `title`,
   `summary`, `any` (the screen's permissions), `keywords` (words people search with
   that the title does not use), and optionally `screens`, `related`, `featured`.
2. Write `server/resources/help/<category>/<slug>.md`. Start with what the screen is for,
   then `##` sections; bold the screen's own labels; use callouts sparingly.
3. Link only to screens the article's readers can open; links to other articles are
   pruned for readers who cannot open them.
4. `HelpCenterTest` checks that every entry has a file and every file an entry, every
   permission exists, every related article exists, every screen is a route, and every
   link resolves.

Keep an article in step with its screen as part of changing the screen — like the
sidebar and the assistant's `SystemGuide`.

## Tests

`tests/Feature/Help/HelpCenterTest.php` (21): the catalogue's integrity (files,
categories, permissions, related reading, screens and links), what Staff, a leave
approver and an HR Manager see on the home and a topic, a whole article with its
neighbours and related reading, 404 for an article that is not the reader's (or not
there, or under the wrong topic), link pruning, the pager and related reading skipping
hidden articles, ranking, permission-filtered and partial search, the empty and
overlong search, Help for this page (exact, `/*`, the wizard, nothing, a page the reader
cannot open, and refusing a non-app address), guests, `auth.assistant`, and
`find_help`'s Help Center cards.
