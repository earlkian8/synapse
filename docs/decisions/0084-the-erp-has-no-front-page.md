# 0084 — The ERP has no front page; `/` goes to sign-in

- **Status:** Accepted
- **Date:** 2026-10-13
- **Related:**
  - [0023 — One identity, many organisations](./0023-identity-and-organization-membership.md)
    (why signing in lands on the workspace picker);
  - [0063 — Privacy Policy and Terms of Service](./0063-privacy-policy-and-terms-of-service.md)
    (the footers that linked them);
  - the landing site: [`landing/README.md`](../../landing/README.md).

## Context

`/` rendered `welcome.tsx`, a small marketing page left over from the starter kit: a
heading, three feature cards and *Get started* / *Log in*. SYNAPSE now has a proper
public front door, the separate landing site in `landing/`, whose *Open SYNAPSE* button
points at the ERP. Following it meant landing on a second, weaker sales page and
clicking *Log in* again. Somebody who types the ERP's address directly wants to sign
in, not to be pitched to.

## Decision

`/` has no page. `HomeController` redirects:

- a **guest** to `login`;
- somebody **signed in** to `fortify.home` (`/workspaces`), the same place signing in
  takes them. The picker passes a single-membership user straight on to the dashboard.

The route keeps its name, `home`, so everything already pointing at it keeps working:
the sign-in screens' logo links (Wayfinder's `home()`), the redirect after logout and
after deleting one's account, and the public pages' `href="/"`. Each now ends on
sign-in, or inside the app for somebody signed in. `RequireCompanySetup` still lets
`home` through, so a signed-in owner mid-setup is not stopped on the way.

`resources/js/pages/welcome.tsx` and its layout case in `app.tsx` are removed.

## Consequences

- Selling the product is the landing site's job alone; the ERP opens straight to work.
- The ERP no longer links to `/careers`, *Get started* or the legal pages from a front
  page. Registration is still linked from sign-in, the legal pages from every footer,
  and each company's careers board from its own URL.
- `/` is a redirect, so it must not get a page again without revisiting this record. The
  sign-in screens' logo now leads back to sign-in. Pointing it at the landing site would
  need the landing site's address in configuration, and nothing needed that yet.
