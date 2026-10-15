# 0088 — The app opens its modules from Services, and Home holds the person's shortcuts

- **Status:** Accepted
- **Date:** 2026-10-15
- **Related:**
  - [0064 — The mobile app is designed as an iOS app](./0064-the-mobile-app-is-designed-as-an-ios-app.md)
    (the floating tab bar and the design system this builds on; its "no raised Clock
    button" still holds);
  - [0080 — Tour versions, a record of every run, and a tour in the app](./0080-tour-versions-per-stop-runs-and-a-tour-in-the-app.md)
    (the tour's stops follow the bar);
  - [0081 — Search finds attendance days, and the app searches one's own](./0081-search-finds-attendance-days-and-the-app-searches-ones-own.md)
    (Search now lists the same services);
  - module doc: [Mobile app](../modules/mobile-app.md).

## Context

The app shipped with five tabs: Home, Attendance, Clock, Leave and Profile. Since then it
gained My schedule, My events, Recognition (kudos and nominations), Points & rewards,
Awards, Holidays and Resignation. None of them had a place in the bar, so each went
where there was room:

- three were Home shortcut cards, and the cards changed with permissions;
- seven were rows on Profile, between the person's photo and their employee record;
- the shift row on Clock linked to My schedule.

Finding Holidays meant knowing it was under Profile. Home's shortcuts were picked by
the app, not the person. Leave had a whole tab though most people file leave a few times
a year, while the modules opened weekly were two taps deep under a screen about
oneself. The look was a list of modules, not a way into them.

Payment super-apps have the same problem with dozens of services and five tabs. GCash
puts a grid of common services on Home under the balance, with a *View all* page
sorted into families. Its middle tab is the one action done every day, Pay QR. Alipay
lets people choose the services on its Home grid.

## Decision

### Five tabs: Home, Attendance, Clock, Services, Profile

- **Clock** stays in the middle and becomes a teal disc with the navy clock glyph: the
  daily action, where the thumb rests. It stays inside the capsule. ADR 0064's reason
  for dropping a button raised out of the bar still holds. When Clock is selected the
  disc wears a ring and the sliding pill fades out. The offline-punch badge sits on the
  disc.
- **Services** replaces **Leave**. It is every module, in five families: *Your time*
  (Clock, Attendance), *Calendar* (My schedule, My events, Holidays), *Time off* (My
  leave, File leave), *Recognition* (Recognition, Send kudos, Nominate, My nominations,
  Points & rewards, My awards) and *Your employment* (My record, Resignation, Account,
  Password). Each family is a card holding a grid of four columns. A field at the top
  opens Search.
- **Leave** is now `app/leave/index.tsx`, a pushed screen with a back button. It is
  unchanged otherwise.
- **Profile** is about oneself again: the employee record, the company, the sign-in
  account, help, appearance and sign-out. The module rows moved to Services.
- A new module never gets a tab. It goes into the catalogue (below), so it appears in
  Services, can be pinned to Home, and is found by Search.

### One catalogue

`features/services/catalog.ts` lists every service once: its key, full title, short
tile label, family, drawing, route, Search keywords, whether it is an action (a form),
and who may open it. The rules are the ones the old rows used: `can_respond_events`,
`can_recognize` (and an employee record to send kudos), `can_resign`, and an employee
record for My schedule. `servicesFor(user)` filters it.

Services, Home and Search read it. Search's own copy of the screen list is gone.

### Home holds the person's shortcuts

Under today's card, Home shows one card with two rows of four: up to **seven** pinned
services and an **All services** tile. The defaults are My leave, File leave, My
schedule, My events, Recognition, Holidays and Points & rewards. Ones the person cannot
open are skipped, and the next default takes their place.

Tapping **Edit** on Services' *On your Home* card, or holding a Home tile, enters edit
mode. Every tile then shows a minus (on Home) or a plus (not on Home). A tap pins or
unpins it. When Home is full, unpinned tiles dim until one is removed. **Restore**
returns to the defaults.

The choice is kept on the phone, in AsyncStorage, per account and company (like the
offline punch queue). It is a preference about this phone's Home, not an HR record.
Nothing about it reaches the server. A service the person loses access to drops off
Home on its own.

My events carries a badge on its tile with the invitations still to answer.

### Drawn service icons

Each service has its own drawing (`features/services/components/service-glyph.tsx`),
made with `react-native-svg` on a 32-unit grid. These are not stock symbols. Each
family has a hue: teal for time, blue for calendar, indigo for time off, coral for
recognition and slate for employment. Each drawing uses three inks cut from that hue:

- the hue itself, for the main shape (at night lifted to 3:1 on the tile, so indigo
  and slate do not sink);
- the hue walked to 3:1 on the tile (`readable()`, 4.5:1 at night), for detail;
- white by day and a near-black of the hue at night, for detail cut out of a shape.

Detail never goes deep-on-fill or paper-on-tile, so the drawings stay legible in both
schemes. An action wears a plus at the corner. The same tile, at 30 pt, leads Search's
*Screens* rows. The SF Symbols and Material Symbols of ADR 0064 stay for the system's
own controls: the tab bar, bar buttons, list rows and status.

### Home's day card shows the shift as a ruler

The progress bar on today's navy card becomes a ruler: a tick for each hour of the
shift, the hours worked filled in teal, and a marker where the day has got to.

### The tour

The app's tour stops at Home, Attendance, Clock, Services and Profile. The Leave stop
(`requests`) became `services`, and Home's and Profile's notes describe the new layout.
`MOBILE_VERSION` stays at 1. People who answered the tour are not asked again, and
*Take the tour* on Profile shows the new one.

## Consequences

- Every module is two taps away at most (Services, then the tile), and the ones a person
  uses are one tap away on Home.
- Leave is a tap further for someone who never pins it. It is pinned by default, along
  with File leave.
- The tour funnel on the web shows `services` instead of `requests` for new runs. Old
  runs keep the ids they showed.
- Shortcuts do not follow a person to a new phone. Syncing them would need a table and
  an endpoint for a preference. Not built.
- Not built: reordering the shortcuts by dragging (they keep the order pinned), an inbox
  of notifications as a tab (the API has none), and services shown by usage.
