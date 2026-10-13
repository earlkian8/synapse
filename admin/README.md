# SYNAPSE admin portal

The console for the people who run SYNAPSE: every organisation and ERP account across
tenants (read-only), the portal's own admin accounts, and an append-only log of what
admins did. It is a static React app that talks to the ERP's API at `/api/admin`.

What it does and how it is secured: [Admin portal](../docs/modules/admin-portal.md) and
[ADR 0085](../docs/decisions/0085-a-platform-admin-portal.md).

## Where it points

`VITE_ERP_URL` is the web ERP's origin, read at build time. Copy `.env.example` to `.env`
(git-ignored). The development address is active and production is commented out:

```dotenv
# Development — the ERP served by `php artisan serve` (server/.env APP_URL).
VITE_ERP_URL=http://localhost:8000

# Production — the deployed ERP.
# VITE_ERP_URL=https://synapse-lzsdm.ondigitalocean.app
```

Swap the comment to build for production. The address also goes into the built page's
Content-Security-Policy (`connect-src`), so a portal built for one ERP cannot call
another.

## Running it

```bash
npm install
npm run dev        # http://localhost:5175, against VITE_ERP_URL
```

The ERP must be running (`php artisan serve` in `server/`), migrated, and have an admin.
Create the first one there. The password is asked for at a hidden prompt:

```bash
php artisan admin:create you@example.com --name="Your Name"   # a super admin
```

At the first sign-in the portal shows a QR code for an authenticator app (Google
Authenticator, Microsoft Authenticator, 1Password, …). A code from it is needed at every
sign-in after that. For a lost phone, another super admin resets the authenticator from
**Admin accounts**. If there is none, run `php artisan admin:reset-two-factor
you@example.com`.

## Checks

```bash
npm run typecheck && npm run lint && npm run format:check && npm run build
```

## Deploying

`npm run build` writes a static site to `dist/`. Serve it from any static host, with
every unknown path falling back to `index.html` (it is a single-page app). Use its own
origin (for example `admin.<your-domain>`), not a path on the landing site.

The build puts a CSP meta tag in `index.html`. Some protections only work as HTTP
headers, so have the host send them:

```
Content-Security-Policy: frame-ancestors 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

The portal is not part of the ERP's Docker image. Its API is: deploying the ERP runs the
migration that creates `admins` and `admin_activity_logs`.

## Behaviour worth knowing

- A session is per browser tab (`sessionStorage`). Opening the portal in a new tab means
  signing in again, and closing the tab ends it.
- A session lasts 8 hours and ends after 30 minutes with no request. A warning shows two
  minutes before the idle limit (`ADMIN_SESSION_LIFETIME` and `ADMIN_IDLE_TIMEOUT` in
  the ERP's `.env`).
- The portal never refreshes data in the background, so an unattended tab cannot keep a
  session alive.
