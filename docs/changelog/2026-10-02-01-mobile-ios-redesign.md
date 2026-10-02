# The mobile app, redesigned as an iOS app

The employee app read like the web ERP in a phone-shaped frame. It had white cards held
apart by grey borders, heavy headings, uppercase labels everywhere, the system font, and
a raised button in the tab bar. Every screen is rebuilt on iOS's own conventions, in the
brand's navy and teal, and it feels the same on Android. Fields now stay clear of the
keyboard on both platforms. See
[ADR 0064](../decisions/0064-the-mobile-app-is-designed-as-an-ios-app.md) and the
[module doc](../modules/mobile-app.md).

## Highlights

- **Large titles that collapse.** Every screen opens on a large title. As it scrolls
  under the navigation bar, the bar frosts over and a small centred title fades in.
- **Fields stay clear of the keyboard.** Every form scrolls with the keyboard, lifting
  the focused field into view as it rises, on iOS and Android
  (`react-native-keyboard-controller`). Dragging the page pulls the keyboard away, and
  Return moves to the next field.
- **A clock face.** The Clock tab is a ring that fills as the shift is worked, around
  the live time and the running time on the clock. A recorded punch lands with a
  check that springs into place.
- **Inter on the HIG type scale, and SF Symbols on iOS** (Material Symbols on Android).
- **The grouped look.** A grey grouped page, borderless white cards with continuous
  corners, and inset grouped lists. A floating tab bar with a sliding selection pill,
  Liquid Glass on iOS 26.
- **Motion with restraint.** One set of springs, short entrances, presses that give way
  under the finger, sheets you drag down to dismiss, and haptics tied to meaning. It
  follows Reduce Motion.
- **Native where it counts.** The compact date picker inline in the leave form on iOS
  and the calendar dialog on Android. System alerts to confirm cancelling a request or
  signing out. The keyboard and alerts match the app's appearance setting.

## Mobile

- **New kit** in `components/ui/`: `Page` (large title, collapsing bar, keyboard-aware
  scroll, `BarButton`/`BarTextButton`), `ListSection`/`ListRow`, `Icon`, `Touchable`,
  `Material`, `Section`, `ProgressBar`, `ToneWell`. `lib/motion.ts` holds the springs
  and entrances.
- **Rewritten**: `AppText` (HIG variants, `tone`, `weight`, `numeric`, weight → Inter
  file), `Button` (iOS styles, a `decorative` form), `Card`, `Input` (filled, animated
  focus edge, `secureToggle`, `ref`), `Sheet` (spring, drag to dismiss, keyboard-aware),
  `Toast` (frosted banner, flick away, error haptic), `Segmented` (sliding thumb),
  `TabBar` (floating capsule), `Pill`, `Avatar`, `Skeleton`, `EmptyState` (+
  `ErrorState` with a retry), `EntryScreen` (keyboard-aware, `grouped`).
- **Removed**: `components/ui/screen.tsx` (every screen is a `Page`) and
  `@expo/vector-icons`.
- **Tokens** (`theme/tokens.ts`): the grouped background, iOS's dark scheme, navy as the
  hero and main action, teal as the tint, Apple's system colours for status, radii with
  `squircle`, a 4pt grid, and the type scale. Every reading colour was checked against
  WCAG AA on page and card.
- **Screens**: all 13 rebuilt. Home has a navy Today card, shortcuts, requests awaiting
  approval and a snapping balance carousel. Clock has the ring, a stat strip, the shift,
  a confirm sheet and the success overlay. Attendance has a distribution bar, a
  calendar of status washes that you swipe to turn the month, and a list. Leave has a
  balance grid, a segmented filter and grouped history. The leave form is a sheet. The
  leave detail confirms its destructive action with a system alert. The day sheet shows
  a punch timeline. Profile is laid out like Settings. Awards, sign-in, register, the
  workspace picker and Join are all rebuilt.
- **Also fixed**:
  - A restored session now leaves the cold-start splash. The root navigator never
    redirected away from `app/index.tsx`.
  - The tab bar's prop type comes from expo-router's own `Tabs`, which cleared the one
    `tsc` error.
  - `useQuery` is no longer handed a `useCallback` (lint).
  - Reanimated shared values are set with `.set()`, which cleared the React Compiler
    lint.
- **Dependencies** (all in Expo Go's SDK 57 bundle): `react-native-keyboard-controller`,
  `expo-blur`, `expo-glass-effect`, `expo-linear-gradient`, `react-native-svg`,
  `@expo-google-fonts/inter`.

## Notes

- **Verified:** `tsc` and `expo lint` are clean (the baseline had 1 type error and 3
  lint errors). `expo export` bundles for iOS and Android.
- **Seen in a browser:** every screen was viewed in light and dark through the web
  build, driven in headless Chrome against a local server on a scratch database
  (dropped afterwards). That covered sign-in, the picker, all five tabs, both sheets,
  the leave form, request and day detail, and awards. Blur, Liquid Glass, SF Symbols,
  haptics and the keyboard are native, so a phone is still the real test.
- Web can't take Reanimated entrances with custom initial values: such a block is left
  out of the flow. On web, `enter()` and the toast fall back to a plain fade.
