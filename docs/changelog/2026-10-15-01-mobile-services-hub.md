# The app gets a Services tab, Home gets your shortcuts, and every module has a drawing

The app had grown seven modules with no place in the tab bar. They sat as Profile rows
and fixed Home cards. Now the bar is **Home · Attendance · Clock · Services · Profile**.
Services holds every module, sorted by what it is for. Home shows the ones you pin, the
way GCash and Alipay handle many services behind five tabs.

See [ADR 0088](../decisions/0088-the-app-opens-its-modules-from-services.md).

## Highlights

- **Services tab.** Every module the person may open, in five families: *Your time*,
  *Calendar*, *Time off*, *Recognition*, *Your employment*. Each family is a card with
  a four-column grid. A field at the top opens Search.
- **Shortcuts on Home.** Under today's card, two rows of four: up to seven pinned
  services and **All services**. Edit them from Services (**Edit** on *On your Home*), or
  hold a tile on Home. Plus and minus badges pin and unpin, and **Restore** brings back
  the defaults. My events shows how many invitations are waiting for an answer.
- **Clock in the middle.** A teal disc in the centre of the capsule, the day's one
  action. It wears a ring when selected and still carries the offline-punch badge.
- **Drawn icons.** Each service has its own two-tone drawing in its family's hue, legible
  in light and dark. Forms (File leave, Send kudos, Nominate) wear a plus.
- **The shift as a ruler.** Today's card shows a tick for each hour of the shift, the
  hours worked in teal, and a marker for now.

## Mobile

- `features/services/catalog.ts`: every service once, with its family, route, drawing,
  Search keywords and who may open it. Services, Home and Search all read it, and
  Search's own screen list is gone.
- `features/services/shortcuts.ts`: the pins, kept on the phone per account and company
  (AsyncStorage), seven at most. Services the person cannot open are skipped.
- `features/services/components/`: `ServiceIcon` (the drawings, `react-native-svg`) and
  `ServiceGrid` / `ServiceTile` / `AllServicesTile`.
- `app/(tabs)/services.tsx` is new. Leave moved from `app/(tabs)/requests.tsx` to
  `app/leave/index.tsx` and now has a back button.
- Profile drops its module rows and keeps the record, company, account, help and
  appearance.
- The tour's Leave stop (`requests`) became `services`, and Home's and Profile's notes
  were rewritten.
- `Icon` gains `services` and `minus`.

## Notes

- No server change. Pins never leave the phone, so they don't follow a person to a new
  phone.
- Not built: drag to reorder shortcuts, an inbox tab (the API has none), and ordering by
  use.
- Checked on the web build at 390 × 844 in light and dark: Home, Services, edit mode,
  Clock, Profile, Leave and Search. Haptics, Liquid Glass and the Android build need a
  phone or the emulator.
