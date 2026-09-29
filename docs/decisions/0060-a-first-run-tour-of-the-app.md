# 0060 — A first-run tour of the app, offered once, for what your role can reach

- **Status:** Accepted
- **Date:** 2026-09-29
- **Related:**
  - [0032 — Guided company setup](./0032-guided-company-setup.md) and
    [0044 — The setup wizard carries every Company Setup screen](./0044-the-setup-wizard-carries-every-company-setup-screen.md)
    (the wizard configures a company; this shows a person around the app);
  - [0059 — The assistant covers the whole system](./0059-assistant-covers-the-whole-system.md)
    (the system guide learns where the tour is);
  - module doc: [Product Tour](../modules/product-tour.md).

## Context

The setup wizard gets a new company configured, but nothing showed anybody around the
app. After setup, the owner arrived at a sidebar of up to 35 links in seven sections,
plus a bell, an inert question mark and a sparkle button with nothing explaining them.
An employee who joined later arrived there without even the wizard. Most apps offer a
short tour on the first visit, with a way out on every card. SYNAPSE had none.

The things to settle:

1. **Where the steps live.** The server knows permissions; only the browser knows what
   is on screen (a phone has no visible sidebar, and the assistant is not offered to
   every role).
2. **Whose state it is.** The tour describes the app, but people belong to several
   workspaces with different roles.
3. **What counts as done**, and whether replays count.
4. **Existing accounts.** Should people who have used the app for months get a tour?
5. **How to build the overlay.** No tour library was installed.

## Decision

**The steps live in the browser; the server keeps only whether the tour is owed.**
`users.tour_finished_at` (null = owed) and `users.tour_outcome` (`completed` or
`skipped`), shared with the page as `auth.tour.owed`, written by
`Support\ProductTour::finish` through `POST /settings/tour`.

**It belongs to the person, not to a workspace.** The app is the same app in every
company, and the tour can be replayed from Help when a new role shows more of it.
Storing it per membership would re-run the same tour in every company somebody joins.

**It shows what the role can reach, from the sidebar's own definition.** The sidebar's
sections and items move into `lib/app-navigation.ts`, used by both the sidebar and the
tour. A section is a stop only if the person can open a screen in it, and its card
lists those screens. A link with no screen behind it yet has no summary and is never
described. A stop whose target is not on screen when the tour starts is passed over.
The HR Manager gets 12 stops and Staff get 6.

**Any way out is an answer, and the first answer stands.** Skip, Escape or the skip
button records `skipped`; ending on the send-off records `completed`. Either one stops
the tour being offered. Replays from Help are never recorded, so a skipped replay
cannot turn a `completed` into a `skipped`. The server is idempotent too, which covers
a double submit.

**Existing accounts are back-filled as finished, with no outcome.** Nobody who is
already using the app is interrupted. The null outcome records that they were never
offered it. Accounts created after the migration owe it, including seeded ones: a
fresh demo database shows the tour once, which is how a demo should begin.

**Built in-house on Radix, not on a tour library.** A stop's card is a modal Radix
Popover (added as the standard shadcn `ui/popover.tsx`; every dependency was already
installed at the same versions) anchored to the lit rectangle. The welcome and the
send-off are Radix Dialogs. The spotlight is one element whose box-shadow is the dim.
This gives us collision-aware placement, focus trapping, Escape handling, scroll lock
and `aria-hidden` on the page from primitives the app already uses, in the app's own
visual language. We did not add react-joyride, driver.js or Shepherd, each of which
brings its own styling, focus model and bundle.

## Consequences

- One definition drives the sidebar. As a side effect, a section with nothing in it
  for the person is no longer drawn as a bare heading. Staff used to see empty *Talent
  Acquisition*, *Workforce* and *Analytics & AI* labels.
- A screen added to the sidebar is covered by the tour automatically. It is listed
  once it has a `summary`.
- The Help button in the top bar was inert. It is now a menu (*Take the tour*, and the
  *Setup Guide* for `setup.company.manage`), and it is shown on phones too, because
  the tour sends people there.
- The tour is not activity-logged. It is the person's own account state, like a
  profile edit.
- **Not done:** versioning the tour so a changed tour is offered again, per-stop
  analytics, and a tour in the mobile app. The columns leave room for the first (a
  version beside the timestamp) if it is ever wanted.
