# The new SYNAPSE mark, as vector, in every slot

SYNAPSE has a new logo: an S drawn as two swooshes and three dots, in a blue gradient.
It replaces the navy figure and teal network everywhere either app shows the mark,
including the browser tab, the installed web app, push notifications, the phone's
launcher and the app's splash screen.

## Highlights

- **One vector source.** The supplied artwork (`server/public/synapse-logo-new.png`,
  1254 px, on an off-white ground) was traced into clean geometry. The two swooshes
  are smooth Bézier outlines, and the dots are a circle, a circle and a slight ellipse,
  as drawn. Each of the five pieces has its own gradient, fitted to its pixels. Laid
  back over the source, the outline sits within 0.1 px of the artwork's edge on
  average, and colour differs by about 2 of 255 levels inside the shapes.
- **Two colourways, both vector.**
  - `synapse-mark.svg` is the original, for light surfaces.
  - `synapse-mark-reversed.svg` is the same gradient lifted until its deepest blue
    clears 4.5:1 on the brand navy (5.9:1). It is used on the navy field and in dark
    mode.
- **A favicon that follows the browser.** `favicon.svg` carries both colourways and
  switches on `prefers-color-scheme`. On a dark browser it uses the lifted blues and
  doesn't sink into the tab bar.
- **Transparent where the platform allows it.** Opaque where it must be.

| Asset | Size | Ground | Mark |
|---|---|---|---|
| `favicon.ico` | 16→256 | transparent | each frame drawn at its own size; 16 and 24 px pieces are drawn 6% and 3% smaller about their own centres, so the gaps that make the S stay open |
| `favicon.svg` | vector | transparent | follows the browser's scheme |
| `favicon-96.png` | 96 | transparent | 92% of the height |
| `apple-touch-icon.png` | 180 | white | 64% of the height (iOS adds the corners) |
| `icon-192/512.png` | 192, 512 | transparent | 86% of the height (PWA `any`) |
| `icon-maskable-512.png` | 512 | white | inside the maskable safe circle |
| `badge-96.png` (new) | 96 | transparent | white silhouette for Android's notification badge |
| mobile `icon.png` | 1024 | white | 62% of the height |
| mobile `icon-dark.png` (new) | 1024 | transparent | lifted colourway (iOS dark icon) |
| mobile `icon-tinted.png` (new) | 1024 | transparent | white-led grey ramp (iOS tinted icon) |
| `android-icon-foreground.png` | 1024 | transparent | inside the adaptive-icon safe zone |
| `android-icon-background.png` | 1024 | white | — |
| `android-icon-monochrome.png` | 1024 | transparent | silhouette for Android's themed icons |
| `splash-icon.png` | 1024 | transparent | inside the circle Android 12+ masks the splash icon to |
| mobile `favicon.png` | 48 | transparent | web build's tab icon |

The launcher tiles are white now, not navy. The new mark is a bright blue whose deeper
end sits at 2.8:1 on navy, and white is the ground it was drawn on.

## Frontend (ERP)

- `AppLogoIcon` serves the SVGs. Its `surface` prop (`auto` | `light` | `dark`) is
  unchanged, and so is every call site: they size the mark by height (`h-7 w-auto`),
  which suits a mark that is a little taller than wide (about 6:7).
- `app.blade.php` links `favicon.svg`. The `.ico` is now labelled `sizes="32x32"`
  instead of `any`, because Chrome otherwise picks the `.ico` over the SVG.
- `site.webmanifest`: `background_color` is white, to match the icons on the
  install splash. `theme_color` stays navy.

## Backend

- Web push (`SystemNotification::toWebPush`) and the service worker's defaults use
  `icon-192.png` as the icon and the white `badge-96.png` as the badge. They used
  `favicon.ico` for both, and Android renders a badge from its alpha only, so a full
  colour icon showed as a solid blob.

## Mobile

- `Logo` draws the mark with `react-native-svg` instead of a PNG, so it is sharp at
  every size and density. Each instance gets its own gradient ids, since ids are
  document-wide on the web build. Its size prop is now `size` (height); `BrandLockup`
  takes `markSize`. The entry screens keep the mark's previous heights (112, 85 and
  67 pt), and the wordmark keeps its sizes.
- `app.json`:
  - `ios.icon` adds the dark and tinted appearances.
  - The adaptive icon's background is white.
  - The app's display `name` is **SYNAPSE**. It was `mobile`, and that was the label
    under the launcher icon.

## Removed

- `server/public/synapse-mark.png`, `synapse-mark-reversed.png`,
  `synapse-logo-transparent.png` and `synapse-logo-white-background.png`, and the
  two mark PNGs under `mobile/assets/images/`. These were the old artwork. Nothing
  references them, and the old logo should no longer be served.

## Verified

- Every asset rendered and inspected in its slot: iOS icon under its corner mask at
  180/120/60 px, the dark and tinted variants, the Android adaptive icon under a
  circle mask, the themed (monochrome) icon, the maskable icon in its safe zone, and
  the favicon frames on a light and a dark tab.
- `expo prebuild` in a scratch copy generates the iOS app icon set with light, dark
  and tinted appearances, and Android's adaptive and monochrome launcher icons. The
  background colour is `#FFFFFF` and `app_name` is SYNAPSE.
- ERP in Chromium against a seeded scratch database, light and dark, at 1440 and
  390 px: marketing page, sign-in, workspace picker. Every new URL returns 200 with
  the right content type, and the old `synapse-mark.png` is a 404. `favicon.svg`
  switches colourway with the browser's scheme.
- Mobile web build at 390 px: sign-in, register and forgot-password in both schemes.
- ERP: `tsc`, ESLint, Prettier, `npm run build`; `php -l` and Pint on the changed PHP;
  Pest 1,929/1,929. Mobile: `tsc`, ESLint on every
  changed file.

## Notes

- **The SVG favicon has no small-size optical variant.** An SVG cannot tell what size
  it is being drawn at, so at 16 px on a 1× screen the gaps between pieces soften.
  The `.ico`'s 16 px frame has the opened-up variant. On hiDPI screens the SVG is drawn
  at 32 px or more and is sharp.
- `synapse-logo-new.png` stays in `server/public/` as the source artwork, as supplied.
  Nothing references it. It is publicly served (1 MB), so move it out of `public/` if
  that matters.
- Store builds still need `ios.bundleIdentifier` and `android.package` in `app.json`.
  Prebuild fills in `com.anonymous.mobile`. This change doesn't pick an identifier.
