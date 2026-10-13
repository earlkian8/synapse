# A landing page for SYNAPSE

SYNAPSE has a public front door in `landing/`: a static site that says what the
system does, sends HR into the web ERP, and hands employees the Android app. It is
separate from `server/`, needs no backend, and isn't in the Docker image.

## Highlights

- **The mark, firing.** The hero builds the blue S from its master vector (the
  swooshes traced, then filled, the three dots landing in order), then a signal runs
  through it for good: top dot, along the upper swoosh, across the gap to the middle
  dot, along the lower swoosh, across to the bottom dot, with a ring opening at each
  dot. The cycle is CSS keyframes on one shared timeline, so the rings stay in step
  without a timer. Three small moments from a working day sit on the dots: a punch
  inside a site fence, an approved leave, an attrition flag.
- **The employee lifecycle as a spine.** Eight stages, from Recruit to Exit, each with
  a drawn miniature of what that screen holds: the pipeline narrowing to a hire, a
  day-one checklist, a manila 201 folder with masked government numbers, a site fence
  on a street grid with a waiting offline punch, leave balances with Bonifacio Day
  not charged, a 360 wheel and an expiring certificate, kudos with points, and
  clearance by department ending in the certificate of employment. The spine is
  measured from where the nodes really are and drawn as you scroll.
- **Insights on navy.** The three models (promotion readiness, performance forecast,
  attrition risk), a risk roster bleeding off the edge with tiers and factors, and
  the assistant filing leave through a visible trace that ends in Confirm.
- **The app, live.** A phone shows the app's Clock screen on Manila's clock: *Time in*
  before 8:02 AM, a filling ring and running hours during the shift, *Done for today*
  after 5 PM. Beside it are the features, *Download the APK*, three install steps, a
  QR code for scanning from a computer, and *Coming soon* badges for Google Play and
  the App Store (not links).

## Frontend (landing/)

- Vite 8, React 19, TypeScript, Tailwind v4 (`@tailwindcss/vite`), Motion 12,
  lucide-react (UI icons inside the phone and the buttons only), `qrcode.react`, and
  Archivo self-hosted through `@fontsource-variable/archivo` with its width axis.
  Headlines run at 125% width, body text at normal width.
- Tokens in `src/index.css`: the blues are lifted from the mark's gradient, ink is the
  brand navy `#0F2044`, manila is kept for the 201 folder alone.
- The mark's geometry lives in `src/lib/mark-geometry.ts`, copied from
  `server/public/synapse-mark.svg` (both colourways). The favicon is
  `server/public/favicon.svg`.
- Destinations come from the build's environment (`.env.example`): `VITE_ERP_URL`
  (default `https://synapse-lzsdm.ondigitalocean.app`; `/login`, `/register`,
  `/privacy` and `/terms` hang off it) and `VITE_APK_URL` (default
  `/downloads/synapse.apk`).
- The nav floats as a pill once the page scrolls, and turns dark over the navy band.
- Prettier is configured like the ERP's (4 spaces, single quotes, the Tailwind
  plugin). The Vite template's demo files are removed.

## Notes

- **No APK is built yet.** `public/downloads/*.apk` is git-ignored. Drop the build
  there before `npm run build`, or host it as a release asset and point
  `VITE_APK_URL` at it. Until then the download links 404.
- `appVersion` in `src/lib/links.ts` (1.0.0) has to be kept in step with
  `mobile/app.json` by hand.
- Motion respects `prefers-reduced-motion` throughout: the mark is shown finished
  and still, the spine fully drawn, the assistant's trace complete.
- The people, figures and dates in the pictures are illustrative. The features are
  real, and the copy was checked against `docs/modules/`.

## Verified

- `tsc -b`, ESLint, Prettier (`--check`) and `npm run build` are green.
- In Chromium (Playwright) at 1440, 1024 and 390 px: the hero sequence and the
  firing, every section, the spine drawing on scroll, the dark nav over the band, the
  sticky phone. No console errors, and no sideways scroll at any width. Also with
  reduced motion emulated, and keyboard focus (visible ring on every link).
- Every link resolves to the ERP's `/`, `/login`, `/register`, `/privacy` and
  `/terms`, the APK path, or an in-page anchor.
