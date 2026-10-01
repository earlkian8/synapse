# The SYNAPSE mark, actually used, at sizes you can see it

The logo existed in `server/public/` and was wired to almost nothing useful. Where it
did appear it was either far too small to read or invisible, and the mobile app didn't
use it at all — its sign-in screen showed a generic fingerprint glyph from Ionicons.

## What was wrong

- **The artwork is landscape, floating in an empty square.** In
  `synapse-logo-transparent.png` the mark occupies 402×302 of a 750×750 canvas — 9% of
  the pixels. Dropped into a 32px slot with `object-contain`, it rendered about **17×13
  px**. Every call site did this, at `size-7`, `size-8` and `size-9`.
- **On dark grounds it was invisible.** Roughly 60% of the mark is deep navy
  (`#001848`–`#183048`). The workspace picker, the invitation page, the marketing page
  and the sign-in shell's left panel all place it directly on the `#0F2044` navy field.
  Four live screens were rendering a logo nobody could see.
- **The browser icons were wrong.** `app.blade.php` served the 750px PNG as the favicon,
  declared the 2160×2160 PNG as `type="image/svg+xml"`, and used that same **1.1 MB**
  file as the apple-touch icon. `site.webmanifest` claimed both were 192×192. A
  perfectly good `favicon.ico` and `apple-touch-icon.png` already sat in `public/`,
  unreferenced.

## The artwork

The assets are built from `synapse-logo-white-background.png`, not the transparent file.
It holds the same mark at **1499×1132** — 3.7× the detail — so the transparency is
recovered by un-compositing it off its white background rather than upscaling the small
one. The build script keys out the background, divides the colour back out of the
residual, trims to the artwork, and derives everything from there.

Two colourways, because one cannot serve both grounds:

- **`synapse-mark.png`** — the original, for light surfaces.
- **`synapse-mark-reversed.png`** — white figure, teal network, for the navy field and
  dark mode. It is a true reversal: the original runs dark→teal along its gradient, so
  the reverse runs light→teal, keeping the brand colour on the network where it belongs
  rather than flattening the whole mark to white.

## Sizes, per slot rather than per file

Nothing is a straight resize; each asset gets the padding its slot wants.

| Asset | Size | Art width | Why |
|---|---|---|---|
| `favicon.ico` | 16→128 | 88–98% | **Each frame drawn at its own size.** Resampling one 256px plate down to 16px turns the mark to mush; at 16px it needs every pixel of the frame. |
| `apple-touch-icon.png` | 180 | 80% | iOS masks its own corners. |
| `icon-192/512.png` | 192, 512 | 80% | PWA install icons. |
| `icon-maskable-512.png` | 512 | 62% | Inside the maskable safe zone, since the OS crops it. |
| mobile `icon.png` | 1024 | 78% | |
| `android-icon-foreground.png` | 1024 | 62% | Adaptive-icon safe zone. |
| `synapse-mark*.png` | 384 wide | — | Rendered at 24–72px, so 384 covers 72px past 5×. |

The OS icons are the reversed mark on a navy `#0F2044` plate, which matches the app's
splash, the manifest's `theme_color` and the sign-in field.

## Wiring

- **`AppLogoIcon` gained a `surface` prop** (`auto` | `light` | `dark`) and reads
  `useAppearance()`. `auto` follows the theme; `dark` is for the navy field, which stays
  dark whatever the user's appearance setting says. The four screens listed above now
  pass `surface="dark"`.
- **Every call site sizes by height** (`h-7 w-auto`) instead of forcing a square box.
  `AppLogo` drops its `aspect-square … ring-1` tile for the same reason — a square plate
  around a 4:3 mark is just padding.
- **Mobile got a matching `Logo` component** (`components/ui/logo.tsx`, same `surface`
  prop, driven by the theme's scheme). It replaces the Ionicons `finger-print` tile on
  the splash and sign-in, and the `person-add` tile on register — those were placeholders
  standing in for a logo the app already had.
- `app.blade.php` and `site.webmanifest` now point at the generated icons, with the
  content types they actually are.
- The Android adaptive icon's plate went from `#E6F4FE` — an Expo template leftover — to
  the brand navy, matching its new background layer.

## Verified

- Walked in a real browser: the ERP sign-in (light and dark), the marketing page, and
  the mobile sign-in at 390×844. The mark reads on the navy field in every one; before,
  it was invisible on all of them.
- Every generated icon inspected at true display size — the app icon at 180/120/87/60/40
  px under an iOS corner mask, and the favicon frames at 16/24/32/48.
- All nine new URLs return 200 with the right content type off the running server.
- `tsc --noEmit` clean in both projects. ESLint and Prettier clean on every touched ERP
  file; `npm run build` succeeds. Mobile: `tsc` clean, ESLint unchanged at its 9
  pre-existing problems.
- No backend change, so no Pest run applies.

## Notes

- **At 16px this mark cannot resolve.** Its thin connector strokes are sub-pixel there,
  and drawing that frame at its own size is as far as resampling can carry it. It is
  sharp from 24px up, and modern browsers use the 32px frame on hiDPI tabs. A crisp 16px
  favicon would need a simplified glyph — a separate piece of design work, not something
  to invent silently.
- `synapse-logo-transparent.png` and `synapse-logo-white-background.png` stay in
  `server/public/` as the source artwork. Nothing references them any more; they are
  publicly served, so move them out of `public/` if that matters.
- The mobile bundle carries both colourways even though only the reversed one is used
  today — `Logo` is the app's logo primitive and handles either ground, so a light-surface
  call site needs no new asset.
