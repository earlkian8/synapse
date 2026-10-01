# The mobile app becomes a white product, like the ERP

The companion app and the web ERP were supposed to read as one product, and on colour
they did not. The ERP's signed-in shell is white: a white page, white cards separated by
neutral hairlines, a near-black `--primary` for anything you press, and the brand teal
`#0ABFBF` held back for one job — showing which thing is active. The mobile app inverted
that. Navy slabs anchored Home and Awards, teal was the fill for every primary control,
and the status palette was a set of Tailwind 500-level colours set as text on white.

That last part is the part you could feel. Teal type on a white card is **2.28:1**. The
amber "pending" chip was 2.15:1, the emerald "Present" pill 2.54:1, the inactive tab
labels 2.57:1 at 11px. WCAG AA asks for 4.5:1. Roughly a third of the text in the app
was below it, and the running "on the clock" counter — the largest number on the Clock
screen — was one of the worst offenders.

## The scheme now

**White is the primary colour.** The page and the cards are both `#FFFFFF` and the edge
between them does the separating, exactly as in `app.css`. The greys are not
approximations of the ERP's: `theme/tokens.ts` carries its `oklch()` values converted to
sRGB, which turn out to be the Tailwind `neutral` ramp, so the two products paint with
the same bytes.

- **Ink, not teal, is what you press.** `primary` is the ERP's `--primary` (`#171717` on
  white, inverting to `#FAFAFA` after dark). The File button, the selected leave filter,
  the calendar's "today" disc and every default `Button` are ink now.
- **Teal marks what is active**, which is the job it does on the ERP's sidebar: the
  selected tab, a selected leave type's edge, the focus ring, the workspace that is
  currently open, links like "See all".
- **One place keeps the brand as a whole shape** — the raised Clock button in the tab
  bar, and the punch button it leads to. This is a time clock; that is the action it
  exists for, and spending the colour there and nowhere else is what stops every screen
  reading as teal.
- **Navy is the pre-app field only.** Sign-in, register, the splash and the workspace
  picker keep the deep-navy backdrop, which is what the ERP's auth screens do too. Past
  that gate there is no navy: Home's hero and the Awards banner are white cards now,
  and the invitation tile on the join screen became the same teal-tinted rounded square
  that marks a company everywhere else.
- **Dark mode is neutral**, not navy-tinted — `.dark` in `app.css` has zero chroma, and
  so does this. The card sits one rung above the page (`#171717` on `#0A0A0A`) because a
  phone has no hover state and RN shadows don't read on black.

## Colour that has to stay legible

`theme/color.ts` is new: sRGB ⇄ OKLCH, WCAG contrast, and `readableOn()`, which walks a
colour's lightness — keeping its hue, trimming chroma only where sRGB can't hold it —
until it clears a target ratio on a given surface. `useTheme()` exposes it as
`readable()`.

This exists because a fixed token table cannot cover the problem. Leave types, award
types and organisations carry colours **the HR team picked in the ERP**. Every status
tone and every one of those tenant colours is now rendered through `readable()` before
it is painted, whether as a pill label, a calendar dot, a metric figure or the bar down
the side of a leave row. A leave type set to white, to yellow, or to black comes out
legible on both schemes.

`Pill` measures against the tint it actually draws, not against the card underneath it,
because a label on a 12% wash of its own colour has slightly less to work with than one
on plain white.

## Two bugs found on the way

- **The sign-in card was broken in dark mode.** It is hardcoded white, but the `Input`s
  and labels inside it resolved against whatever scheme the phone was set to — pale grey
  type on white, about 2:1, on every dark-mode phone. New `FixedScheme` pins a subtree to
  one scheme; login, register and the workspace picker's card now declare the surface
  they actually are.
- **`useSystemScheme()` can report `'unspecified'`**, which `?? 'light'` doesn't catch —
  a live `tsc` error in `theme/theme.tsx` on the branch. Narrowed properly.

## Notes

- `Button`'s `secondary` variant (navy fill) had no call sites and is gone; `accent`
  takes its place as the teal punch button. A disabled button no longer drops to 55%
  opacity, which took its label down to 2.9:1 — it goes to a quiet grey fill with a
  muted label instead.
- The `accentBorder` token was folded into `accent`. On white the brand teal is a 2.3:1
  shape — an edge nobody can find — so the light scheme's teal fill is deepened to
  `#00A5A6` (3.05:1). Dark mode keeps `#0ABFBF` untouched, where it is already 7.9:1.
  `palette.teal` stays exactly the brand teal for the navy field.
- The Clock screen's "all done" tick was teal; it is the app's success green now, so it
  agrees with the Present pill and the success toast. The confirmation burst keeps the
  punch type's colour, pulled to a shade its tick can sit on.
- The toast picked up the ERP's Sonner treatment — a card washed with 8% of the status
  colour and a solid bar down the leading edge, rather than a fully saturated banner.

## Verified

- **A contrast audit over the committed tokens** (not a copy of them): 138 pairings —
  every text and surface combination the app renders, in both schemes, including all
  nine status tones as pills and as bare text, the toast, the confirmation burst, the
  navy field, and six deliberately awkward tenant colours. All clear WCAG AA (4.5:1 for
  text, 3:1 for a shape carrying meaning on its own).
- **Walked in a real browser** at 390×844, light and dark, signed in against the seeded
  demo tenant: Home, Clock, Attendance (calendar and list), Leave, File Leave, Profile,
  Awards and the sign-in screen.
- `tsc --noEmit` clean apart from one pre-existing error in `app/(tabs)/_layout.tsx` (an
  expo-router / `@react-navigation/bottom-tabs` `BottomTabBarProps` type mismatch,
  unrelated and untouched). ESLint: 9 problems before this change and 9 after — the same
  pre-existing ones in `lib/use-query.ts`, `app/join.tsx` and the reanimated shared value
  in `Button`. `mobile/` has no Prettier config; it is not Prettier-managed.
- No backend change, so no Pest run applies here.

## Not done

- `app.json`'s Android `adaptiveIcon.backgroundColor` is still `#E6F4FE`, a leftover from
  the Expo template rather than a brand colour. Changing it without seeing how it sits
  behind the icon's foreground layer would be guesswork; worth a look with the icon
  assets in hand.
- `expo-secure-store` has no web implementation, so the app cannot boot in a browser
  without a shim. That is fine — the web target is not shipped — but it does mean
  browser-based checks need a throwaway local patch.
