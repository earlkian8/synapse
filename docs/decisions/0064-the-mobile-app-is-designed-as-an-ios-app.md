# 0064 — The mobile app is designed as an iOS app, in the brand's colours

- **Status:** Accepted
- **Date:** 2026-10-02
- **Related:**
  - [0020 — Mobile employee companion app](./0020-mobile-companion-app.md) (the app this
    redesigns);
  - [0040 — Punch capture, geofences, device ingestion](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md)
    (the offline queue the Clock screen and tab bar show);
  - module doc: [Mobile app](../modules/mobile-app.md).

## Context

The mobile app's design was ported from the web ERP. Its tokens were "in lock-step with
`app.css`": white page, white cards held apart by grey borders, 800-weight headings,
uppercase labels over every block, the system font, a raised circular button in the
middle of the tab bar, and Ionicons everywhere. On a phone that reads as a web page in
a frame, and as generated rather than designed. People open this app several times a
day to clock in, so how it feels is most of what it is.

Three practical problems sat alongside the look:

1. **The keyboard covered fields.** `KeyboardAvoidingView` was used only on iOS
   (`behavior={undefined}` on Android) and only on the entry screens. Filing leave, the
   reason field sat under the keyboard.
2. **Nothing moved like the platform.** Sheets faded rather than rose, presses dimmed,
   and there was no shared motion language.
3. **A restored session stayed on the splash.** The root navigator never sent an
   authenticated person on from `app/index.tsx`, the route a cold start lands on.

## Decision

**Structure from Apple's Human Interface Guidelines, colour from the brand.** The app now
follows iOS's own layout conventions on both platforms:

- **The grouped background.** Content sits on `#F2F2F7` by day and true black at night,
  in cards with continuous (squircle) corners and no edge. The difference in shade, not
  a border, separates them. The dark scheme is iOS's: `#000` page, `#1C1C1E` cards,
  `#2C2C2E` raised surfaces.
- **Large titles that collapse.** Every screen is a `Page`: a large title in the
  content, and a navigation bar over it that frosts over, gains a hairline and shows a
  small centred title as the large one scrolls under. All of it runs from one scroll
  value on the UI thread.
- **Inset grouped lists** (`ListSection` / `ListRow`) for anything that is a set of
  labelled values or places to go: Profile, the leave form, sheets.
- **A floating tab bar.** It is a capsule the content scrolls under, with a pill that
  springs to the selected tab and SF Symbols that fill when selected. The raised Clock
  button is gone. Clock is a tab like the others, and its screen is the hero.
- **The HIG type scale, set in Inter.** Each style sits a point under Apple's size,
  because Inter's x-height is taller than SF Pro's. Inter's own tracking curve keeps
  large titles tight and small text open. Tabular figures are used wherever numbers
  tick or line up. Inter rather than the system font, so Android gets the same type.
  Inter rather than the ERP's Instrument Sans, because it sits closest to SF Pro and
  has the tabular figures a clock needs.
- **SF Symbols on iOS, Material Symbols on Android** (`expo-symbols`). One `Icon`
  component maps a meaning ("clockIn") to both, so each platform shows the icons its
  people already read.

**Colour is spent where it carries meaning.** Navy is the brand's weight: the one hero
surface on a screen (today's card, the clock face) and the main action. Teal is the
iOS tint: links, selection, switches, progress, and the fill of the punch button. Status
tones are Apple's system colours. Every reading colour clears WCAG AA on both the page
and the card. `readable()` still adjusts colours HR picks so they stay legible.
`textTertiary` is for glyphs and placeholders only (3:1).

**The platform's materials where they exist.** Liquid Glass (`expo-glass-effect`) on
iOS 26 for floating chrome, the system chrome blur (`expo-blur`) before it. Android gets
a 94% fill instead of a blur, because a real blur there needs a capture view around
every screen and costs frames on mid-range phones.

**The keyboard is handled once, everywhere** (`react-native-keyboard-controller`). Every
`Page` and every entry screen scrolls in a `KeyboardAwareScrollView`, so a focused field
is lifted clear of the keyboard in step with its animation, on iOS and Android alike,
and a drag down the page pulls the keyboard away. Sheets sit in its
`KeyboardAvoidingView`. Forms chain Return from field to field, and pass autofill hints
(`textContentType`, `autoComplete`).

**One motion vocabulary** (`lib/motion.ts`). It has four springs, chosen by job, all
close to critically damped. Only the punch confirmation is allowed a little overshoot.
Blocks enter with a 12pt rise and a 40ms stagger. Every press gives way on a spring
(`Touchable`), or highlights like a table cell for list rows. Sheets rise on a spring
and follow a finger down to dismiss. Reanimated follows the phone's Reduce Motion
setting. Haptics are tied to meaning: a selection tick for choosing, a light tap for
pressing, a medium one for the main action, success when a punch lands, and error when a
toast reports one.

**Native controls where the platform has them.** iOS's compact date picker sits inline
in the leave form's rows, and Android opens its calendar dialog. Confirming a
destructive action (cancel a request, sign out) is a system alert. The chosen
appearance is handed to the platform (`Appearance.setColorScheme`), so the keyboard,
alerts and date picker match the app.

**All of it stays in Expo Go.** Every native module added (keyboard-controller,
expo-blur, expo-glass-effect, expo-linear-gradient, react-native-svg) is in Expo Go's
SDK 57 bundle. Nothing needs a development build.

## Consequences

- `components/ui/screen.tsx` is gone. A screen is a `Page`. Pages in the tab bar pass
  `tabInset` so their last row clears the floating bar (`useTabBarInset`).
- `AppText` variants are the HIG names (`largeTitle` … `caption2`), with `tone` and
  `weight` props. Android can't pick a weight inside a custom family, so `AppText` maps
  any `fontWeight` to its Inter file. Text must go through `AppText` to get the face.
- Theme tokens changed names: `tint`/`tintText`/`tintSoft`, `textSecondary`/
  `textTertiary`, `fill`, `separator`, `hero`. The ERP's border-separated white look is
  no longer the mobile reference.
- `@expo/vector-icons` is removed. New glyphs are added to the `Icon` map with an SF
  Symbol name and a Material Symbol name. `tsc` checks the first, and the second must
  exist in `expo-symbols`' symbol list.
- Reanimated's web layout animations don't support custom initial values: such a block
  is left positioned out of the flow. `enter()` and the toast fall back to a plain fade
  on web, which is a development preview rather than a target.
- The fix to the cold-start redirect means a restored session now leaves the splash for
  the tabs.
