# 0080 — Tour versions, a record of every run, and a tour in the app

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0060 — A first-run tour of the app](./0060-a-first-run-tour-of-the-app.md)
    (the tour this extends);
  - [0064 — The mobile app is designed as an iOS app](./0064-the-mobile-app-is-designed-as-an-ios-app.md);
  - module doc: [Product Tour](../modules/product-tour.md).

## Context

The tour was offered once and remembered as answered. When it gained a stop, such
as search, nobody who had taken it would ever see the new one. Nobody knew whether
people finished it or where they gave up, because only the first answer was stored.
The mobile app had no tour at all.

## Decision

### The tour has a version

`ProductTour::VERSION` is **2**: version 2 added the *Search* stop.

`users.tour_version` records the version a person answered. Everybody who had
finished the tour was back-filled to 1.

`ProductTour::mode()` decides what is owed:

- **first**: never answered;
- **whats_new**: answered an earlier version;
- nothing.

A *What's new* run shows only the stops marked `since` a version after the one
answered, with its own welcome and send-off. Answering it moves the version on; it
never changes the first answer. Replays from the Help menu change nothing.

To add a stop, raise `VERSION` and mark the stop `since` it.

### Every run is recorded

`product_tour_runs` holds one row per run. Each row has:

- user, platform (`web` | `mobile`) and version;
- mode (`first` | `whats_new` | `replay`);
- the stop ids the run showed. A role that cannot open a section never sees its
  stop, so runs differ;
- the furthest stop reached and the last one shown;
- the outcome (`completed` | `skipped`), with start and end times.

The browser and the app open a run when the tour starts, move it on at each stop
(going back never lowers how far it got) and close it with the answer.

`ProductTourFunnel` reads them for one platform and version, over 1, 3, 6 or 12
months:

- runs started (and by how many people), walked to the end, skipped, and
  **abandoned** (no answer an hour after starting);
- **stop by stop**: how many runs showed it, how many reached it, and how many ended
  there without finishing.

**System → Product Tour** (`product-tour.view`, granted to HR Manager) shows the
totals as tiles and the stops as a table with a reach bar. A stop where a quarter or
more of the leavers left is marked.

### The app has its own tour

`MOBILE_VERSION` is **1**. `users.mobile_tour_version` and `mobile_tour_finished_at`
record it apart from the web's: the two are different walks over different screens.

The app's tour is a spotlight over the tab bar. It stops at Home, Attendance,
Clock, Leave and Profile, with a welcome and a send-off. `MobileSession` reports
whether it is owed. It is offered once after sign-in and replayed from **Profile →
Take the tour**. Its runs are recorded like the web's.

## Consequences

- A new stop reaches everybody once, as a short *What's new*, rather than nobody.
- The data is per workspace and per person, and never leaves the workspace.
  `product_tour_runs` is left out of the data export: it is product telemetry, not an
  HR record.
- Not built: per-role or per-workspace tours, and A/B tests of wording.
