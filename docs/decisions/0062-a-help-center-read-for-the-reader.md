# 0062 — A Help Center in the app, written as Markdown and read for the reader

- **Status:** Accepted
- **Date:** 2026-10-01
- **Related:**
  - [0059 — The assistant covers the whole system](./0059-assistant-covers-the-whole-system.md)
    (its `SystemGuide` is read for the asker in the same way, and now points at the
    articles);
  - [0060 — A first-run tour of the app](./0060-a-first-run-tour-of-the-app.md) (the
    Help menu it introduced is where the Help Center is reached);
  - module doc: [Help Center](../modules/help-center.md).

## Context

SYNAPSE had three ways to learn it: the one-minute tour, the Setup Guide, and the
assistant. None is a manual. The tour is a glance, the Setup Guide configures rather
than explains, and the assistant is not offered to Staff and spends model quota on every
question. Nothing explained, in words that last, how a day of attendance is judged, how
leave days are counted, or what to do when a punch is refused — and the docs in this
repository are written for developers.

The things to settle:

1. **Where the content lives**: a CMS table, a static site, or the app's own code.
2. **Who sees which article**, given that most screens are permission-gated.
3. **How it is found**: browsing, search, and from the page somebody is stuck on.
4. **How it relates to the assistant**, which already answers "how do I…".

## Decision

**Articles are Markdown files in the app, catalogued in PHP.** Each article is
`server/resources/help/<category>/<slug>.md`; `App\Support\Help\HelpCenter` holds the
catalogue — title, summary, topic, keywords, the screens it explains, related reading,
and who may read it. The manual ships with the code it describes, is reviewed in the
same change as a screen, and a test fails when a link, a permission or a screen it names
stops existing. The metadata is PHP rather than front matter because the YAML parser is a
development-only dependency, and a typed constant is what `SystemGuide` already uses.

**Read for the reader, by the screen's own permissions.** An article carries the
permissions of the screen it explains; empty means everybody. One filter decides the
topics, the search, the sidebar, related reading, the pager and "Help for this page".
An article the reader may not open answers 404, the same as one that does not exist, and
a link to it inside another article keeps its words but loses its address. The manual
therefore cannot be used to map the parts of the system somebody has no access to —
the rule `SystemGuide` follows for the assistant.

**Who is offered the assistant moves to the server.** The articles about the assistant
need the same answer the launcher gives, and that list lived only in the browser.
`App\Services\Assistant\AssistantAccess` now holds it, shared as `auth.assistant`, and the
launcher reads that. One rule, two readers.

**Found three ways.** Browsing by topic (the sidebar's sections), a weighted search over
titles, keywords, summaries and bodies that returns only complete matches unless none
exists, and **Help for this page**, which maps the current address to the most specific
article through each entry's `screens`.

**The assistant points at the manual.** `find_help` returns up to two articles that match
every word of the question, after the screens it already returned, and the Help Center's
*Still need help?* opens the assistant with the question started — never sent, because
a turn spends quota.

**Rendered in the browser with what is installed.** react-markdown and remark-gfm were
already used for the assistant's replies. A small remark plugin adds heading anchors and
callouts; no new dependency.

## Consequences

- Changing a screen now includes changing its article — like the sidebar and
  `SystemGuide`. `HelpCenterTest` catches broken links and stale permissions, not stale
  words.
- The Staff role, which gets neither the assistant nor most screens, has a manual: 14
  articles about what it can do.
- The app shell's content column is `overflow-x-clip` instead of `overflow-x-hidden`.
  The old value made it a scroll container, which disabled every sticky element inside
  it, including the top bar that was meant to stick.
- The footer's *Docs* link, a placeholder until now, opens the Help Center.
- **Not done:** articles written by a company for its own people (its policies, its
  handbook), translations, "was this helpful?" feedback, screenshots, and full-text
  search beyond the catalogue's words. Company-written pages would need a table and an
  editor; this decision keeps the product's manual in the product.
