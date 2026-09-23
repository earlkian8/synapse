# The mobile entry screens go white, with navy second

The app became a white product in September (see
[the white-first colour scheme](./2026-09-09-01-mobile-white-first-colour-scheme.md)),
but its first screens did not. The cold-start splash, sign-in, register and the
workspace picker were still a full navy field with a white card floating on it. That's
the first thing anyone sees, and it read as a navy app. The ERP's own sign-in on a phone
is already white: its navy panel is hidden below `lg`, leaving a white page, the mark in
its original colourway and a navy wordmark.

Now white is the primary colour on every screen. Navy is the secondary colour, with a
defined job, and teal still marks what is active.

## Highlights

- **Sign-in and register are white.** The mark sits above a navy **SYNAPSE** wordmark
  in its original colourway: navy figure, teal network. The form sits straight on the
  page with no card, because a white card on a white page says nothing. The headings
  are ink.
- **Navy is what you press on the way in.** **Sign in** and **Create account** are
  navy buttons with white labels, and **Create an account** and **Sign in** are navy
  links. An empty form's button is the quiet grey it always was.
- **The workspace picker** is white, with a navy workspace tile. Companies are rows
  edged like the app's cards, and teal still rings and names the workspace already open.
- **The splash is white.** The native splash and the cold-start screen show the mark and
  wordmark on white. The splash icon is a new cut of the ERP's high-resolution mark;
  the old one was the white "reversed" mark, which would have vanished.
- **The entry screens are white whatever the phone is set to**, and their status-bar
  icons are dark. The screens after sign-in still follow the phone's light or dark
  setting.

## Mobile

- **Theme** (`theme/tokens.ts`): the scheme gains `secondary`, `onSecondary` and
  `secondaryText`.
  - Light: navy `#0F2044` (16:1 on white) with white type.
  - Dark: `#4064A8` as a fill (3.1:1 on the dark card, white type on it at 5.6:1) and
    `#A8B9E6` as type (9.2:1).
  - `palette.navyDeep` is gone. The header comment now says navy is second.
- **`Button`** gains `variant="secondary"`.
- **New `components/ui/entry-screen.tsx`:**
  - `EntryScreen` is the white ground: `FixedScheme` light, a dark `StatusBar` and the
    safe area.
  - `BrandLockup` is the mark over the navy wordmark, with an optional tagline.
  - `entryColors` is the light palette, for a screen that reads colours outside the
    ground.
- **`app/(auth)/login.tsx`, `app/(auth)/register.tsx`, `app/select-workspace.tsx`,
  `app/index.tsx`** are rebuilt on it. The password toggle and the workspace rows gain
  accessibility labels.
- **`app.json`**: the native splash is `#FFFFFF` in both schemes, and the dark override
  is dropped. `assets/images/splash-icon.png` is regenerated from
  `server/public/synapse-logo-white-background.png`: cropped, un-matted from white to a
  transparent edge, 640 wide.
- **Docs:** the `Logo`, `FixedScheme` and workspace-picker comments no longer describe
  a navy field.

## Notes

- **Unchanged:**
  - The launcher icon's navy tile. It's the app icon, not a screen.
  - The screens after sign-in, which were already white and neutral, with teal only
    for the Clock action and for marking what is active.
  - The join screen, which is part of the app and follows the phone's scheme.
- **Verification:**
  - Mobile tsc reports only the existing `app/(tabs)/_layout.tsx` error.
  - ESLint on `app`, `components` and `theme` reports three errors that are all on
    `HEAD` too: two in `Button`'s press animation and one in `join.tsx`.
  - Walked in Expo web at 390×844 with the phone set to light and to dark: sign-in empty
    and filled, register, and a real sign-in into the workspace picker. The entry
    screens are white in both schemes.
  - The status bar can't be seen on web. On web the cold-start route is a blank white
    page until the bundle loads, so its lockup was checked through the sign-in screen,
    which renders the same component.
