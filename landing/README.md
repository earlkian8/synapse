# SYNAPSE landing page

The public front door: what SYNAPSE does, a way into the web ERP, and the Android
app's download. It is a static site (React 19, Vite, Tailwind v4, Motion), separate
from `server/`. It needs no backend and isn't in the Docker image.

## Running it

```bash
npm install
npm run dev          # http://localhost:5173
npm run build        # tsc -b, then the static site in dist/
npm run preview      # serve dist/ on http://localhost:4173
```

Checks: `npm run typecheck`, `npm run lint`, `npm run format:check`.

## Where it sends people

Both destinations are set at build time (copy `.env.example` to `.env`):

| Variable       | Default                                    | Used by                                                                                                                       |
| -------------- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| `VITE_ERP_URL` | `https://synapse-lzsdm.ondigitalocean.app` | _Open SYNAPSE_, _Sign in_ (`/login`), _Create a workspace_ (`/register`), the privacy policy and terms (`/privacy`, `/terms`) |
| `VITE_APK_URL` | `/downloads/synapse.apk`                   | every _Download_ button and the QR code                                                                                       |

**The APK is not in the repository.** Build it from `mobile/` (an EAS or local
Android build), then either put it at `public/downloads/synapse.apk` before
`npm run build` (the path is git-ignored), or host it elsewhere (a GitHub release
asset, a storage bucket) and set `VITE_APK_URL` to its full URL. The QR code
always encodes an absolute address, so a phone can scan it from a computer.
The version shown next to the button is `appVersion` in `src/lib/links.ts`, kept
in step with `mobile/app.json`.

The Play Store and App Store appear as _Coming soon_ badges. They are not links.

## How it is put together

```
src/
  lib/links.ts             the ERP and APK addresses, the app version
  lib/mark-geometry.ts     the mark's vector pieces and gradients (from server/public/synapse-mark.svg)
  components/mark.tsx      the static mark and the header lockup
  components/firing-mark.tsx  the hero's mark: built on load, then a signal fires through it
  components/actions.tsx   buttons, the APK link, the store badges
  components/nav.tsx       the floating bar (turns dark over the navy band)
  sections/hero.tsx
  sections/lifecycle/      the eight stages, the scroll-drawn spine, one drawn miniature each
  sections/insights.tsx    the three models, the risk roster, the assistant
  sections/app-download.tsx  the live Clock-screen phone, the download panel
  sections/footer.tsx
```

- **Type** is Archivo, self-hosted (`@fontsource-variable/archivo`), with its width
  axis. Headlines run wide (`font-wide`, 125%), body text at normal width.
- **Colour** tokens are in `src/index.css`. The blues come from the mark's own
  gradient, ink is the brand navy `#0F2044`, and manila is reserved for the 201
  folder.
- **Motion:** one load sequence in the hero (the mark is traced, its dots land,
  then a CSS-keyframed pulse runs dot to dot), and the lifecycle spine, which
  draws as you scroll. Everything respects `prefers-reduced-motion`: the mark is
  shown finished and still, and the spine fully drawn.
- **The spine** is measured, not drawn by hand: a `ResizeObserver` reads where each
  stage's node is and runs a smooth curve through them, so it fits at any width.
- **The phone** shows the app's Clock screen on Manila's clock: _Time in_ before
  8:02 AM, a filling ring and running hours during the shift, _Done for today_
  after 5 PM.
- People, numbers and dates in the pictures are illustrative. The features they
  show are real (see `docs/modules/`).
