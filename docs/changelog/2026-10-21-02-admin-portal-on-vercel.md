# The admin portal deploys to Vercel

The admin portal (`admin/`) is hosted on Vercel. It now carries a `vercel.json`, so a
deployment there behaves the way [`admin/README.md`](../../admin/README.md) asks of any
host. Reloading or opening a link to a screen like `/organisations` no longer 404s, and
the security headers are sent.

## Highlights

- **`admin/vercel.json`.** It builds with Vite (`npm ci`, `npm run build`, `dist/`) and
  sends every path that is not a file to `index.html` (the portal uses browser routes).
  It sends the headers the README lists: `frame-ancestors 'none'`, `X-Frame-Options:
  DENY`, `nosniff`, `Referrer-Policy: no-referrer` and HSTS. It adds `X-Robots-Tag:
  noindex, nofollow` and caches the hashed files in `assets/` for a year.
- **A Vercel build without `VITE_ERP_URL` fails.** `.env` is not committed, so the build
  used to fall back to `http://localhost:8000` without saying so. The Content-Security-Policy
  then only allowed localhost, so sign-in could not work. The build now stops with a
  message naming the setting. Local builds are unchanged.

## Notes

- In the Vercel project, set Root Directory to `admin`. Set `VITE_ERP_URL` to the
  deployed ERP (no trailing slash) under Environment Variables, and redeploy after
  changing it, because it is read at build time.
- The ERP needs no change. Its API uses Laravel's default CORS (any origin), and the
  portal signs in with a bearer token, without cookies.
- README and [deployment](../deployment.md) updated.
