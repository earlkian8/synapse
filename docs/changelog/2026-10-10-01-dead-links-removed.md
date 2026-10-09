# Links that went nowhere are gone

An audit of the whole codebase for features shown but not built found two links that
led nowhere. Both are removed.

## Highlights

- **No more "Email & Notifications".** The Company Setup sidebar link pointed at
  `/setup/notifications`, which never had a route, and it had no permission, so every
  user, Staff included, saw a section whose only link 404'd. Staff now have no Company
  Setup section, as the tour already assumed. Notification preferences stay per person,
  under System → Notifications.
- **No more "Support".** The footer under every page and the sign-in / register footer
  linked to `#`.

## Frontend

- `lib/app-navigation.ts`: the Email & Notifications item and its `Mail` import are
  gone. `AppNavItem.summary` is now required: it was optional only so a link with no
  screen behind it could be left out of the tour.
- `features/product-tour/steps.ts`: the section stop lists the group's items directly.
  The filter for summary-less links, and the skip for a section made only of them,
  could no longer match.
- `components/app-footer.tsx` and `layouts/auth/auth-simple-layout.tsx`: the *Support*
  links are removed.

## Docs

- `modules/product-tour.md` and `modules/company-setup-wizard.md` no longer describe
  the link with no screen.
- `testing-guide.src.html`: the "Two dead sidebar links" known gap is gone (the other
  link, Data Backup & Export, became Data Export).

## Notes

- **Verified:** tsc, ESLint, Prettier and the build pass. The Pest suite was run in
  full (see the commit message for the count). No server code changed.
- **The footer's status line is still made up.** "All systems operational" and "99.98%
  uptime this month" are hard-coded, as is `v1.0.0`. Nothing measures either. Left for
  a decision: remove it, or wire it to something real.
- The testing guide's *Known gaps* table is otherwise out of date (it still calls
  Attrition Risk a browser-only demo and the models borrowed). Not changed here.
