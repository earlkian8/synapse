# A first-run tour of the app

Everybody is now shown around SYNAPSE the first time they reach the app: the owner
straight after company setup, and every employee who joins after them. A spotlight
moves across each part of the screen their role can use, with a card beside it. At
the end the tour suggests where to start. It takes about a minute, can be skipped on
any card, is offered only once, and can be replayed from **Help** in the top bar. See
[ADR 0060](../decisions/0060-a-first-run-tour-of-the-app.md) and the
[Product Tour](../modules/product-tour.md) module doc.

## Highlights

- **A welcome, then one stop at a time.** The welcome says what the tour is, how long
  it is, and lists its stops. Each stop lights up its target (a sidebar section, the
  bell, Help, the account menu, the assistant) and explains it. A section's card lists
  the screens in it that the person can open.
- **Only what your role can reach.** An HR Manager gets 12 stops and Staff get 6. A
  link with no screen behind it yet is never described. On a phone, where the sidebar
  is hidden, the tour covers the top bar and the assistant.
- **A send-off that goes somewhere.** It suggests up to three first steps for the role:
  *Bring your people in* and *Finish setting up* for an owner, *Your time record* for
  staff.
- **Skip is always one click, or Escape.** A click on the dimmed page does nothing, so
  nobody leaves by accident. After a skip, a toast says where to replay the tour.
- **Help works now.** The question mark in the top bar was inert. It is now a menu:
  *Take the tour*, and the *Setup Guide* for whoever sets the company up. It is also
  shown on phones.
- **Keyboard and screen readers.** Every card is a labelled modal dialog. Focus opens
  on *Next*, → and ← step through, and Escape leaves. Motion stops under
  `prefers-reduced-motion`.

## Backend

- Migration `2026_09_29_000000_add_product_tour_to_users`: `users.tour_finished_at` and
  `users.tour_outcome`. Every existing account is back-filled as finished (with no
  outcome) so nobody already using the app is interrupted. `updated_at` is untouched.
- `Support\ProductTour`: `owes()`, and `finish()`, which keeps the first answer, so a
  replay or a double submit cannot rewrite it. It is written without touching
  `updated_at`.
- `POST /settings/tour` (`tour.finish`) → `Settings\ProductTourController@finish`,
  validated by `Settings\FinishProductTourRequest`, answering `204`.
- `HandleInertiaRequests` shares `auth.tour.owed`. Both columns are `#[Hidden]` on
  `User`, so the shared prop is the only copy the browser gets.
- `SystemGuide` has a `tour` entry, so the assistant can answer "how do I replay the
  tutorial?".

## Frontend

- New feature `features/product-tour/`: the steps, a module-level store, the
  orchestration hook, a per-frame target tracker, the API call (it accepts only `204`),
  the spotlight, the stop card (a modal Radix Popover), the welcome and the send-off
  (on the navy `SynapseField`), and the Help menu. It is mounted once, beside the
  assistant, in the sidebar layout.
- **The sidebar has a single definition.** Sections and items moved from
  `app-sidebar.tsx` into `lib/app-navigation.ts`, each item with a one-line `summary`.
  `useAppNavigation()` returns what the person can see, and both the sidebar and the
  tour read it.
- **Empty sidebar sections are gone.** Staff used to see bare *Talent Acquisition*,
  *Workforce* and *Analytics & AI* headings with nothing under them.
- Tour anchors (`data-tour`) on the sidebar brand, every section, the bell, Help, the
  avatar and the assistant launcher.
- Added the standard shadcn `components/ui/popover.tsx` with `@radix-ui/react-popover`
  1.1.23. Every dependency it needs was already installed at the same version.

## Verified

- **Pest:** the full suite passes (1413 tests, 13 of them new).
- **Pint, tsc, ESLint, Prettier and the build** all pass.
- **Headless Chromium**, against a freshly seeded throwaway database:
  - The owner at 1440×900 was offered the tour on arrival and walked all 12 stops. ←
    and → navigation worked. One `POST … 204 {"outcome":"completed"}` was sent. A
    reload did not offer it again. A replay from Help sent nothing.
  - A new Staff account got 6 stops, with no Company Setup stop and no assistant.
    Their send-off offered *Your time record* and *Complete your profile*. Escape on a
    stop recorded `skipped` and showed the replay toast. The page's scroll and pointer
    locks were fully released.
  - At phone width (390×844) there were 4 stops (the sidebar is hidden) and the layout
    was clean.
  - Dark mode was checked.
  - No console errors or warnings in any run.

## Tests

- `Settings/ProductTourTest` (13): owed flag shared with the shell; tour state kept off
  the user payload; completed and skipped both recorded; the first answer stands;
  `updated_at` left alone; bad, missing and non-string outcomes refused; guests
  refused; a new registration owes the tour; the migration back-fills existing accounts
  (with no outcome, and without moving `updated_at`); the assistant's guide finds the
  tour.

## Notes

- A freshly seeded database offers the demo login the tour once. Seeded accounts are
  created after the migration, which is intended: a demo should begin with it.
- The tour is not activity-logged. It is the person's own account state, like a
  profile edit.
- **Not done:** re-offering a changed tour (versioning), per-stop analytics, and a tour
  in the mobile app.
