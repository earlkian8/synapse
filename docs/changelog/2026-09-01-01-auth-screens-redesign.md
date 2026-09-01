# The auth screens catch up to the rest of the product

Login, register, forgot/reset password, the passkey and two-factor flows, email
verification and password confirmation were still the stock shadcn/Fortify
scaffolding — plain stacked `Label`/`Input` fields with no icons, ad-hoc green
`<div>` status text, and a seven-field register form in one unbroken column —
sitting inside a shell (`auth-simple-layout.tsx`) that was already carrying the
navy/teal SYNAPSE brand. The shell was fine; the forms inside it weren't.

## What changed

- **A shared field system.** New `components/icon-input.tsx` gives every text
  field a leading Lucide icon with the same teal focus treatment
  (`focus:border-[#0ABFBF] focus:ring-[#0ABFBF]/30`) already established by the
  workspace picker's search box (`pages/workspaces.tsx`). `PasswordInput` grew
  an optional `icon` prop (additive — every existing call site outside auth is
  unaffected).
- **One signature backdrop, not two.** The workspace picker's neural-node field
  (`SynapseField`) is now `components/synapse-field.tsx`, reused by both
  `pages/workspaces.tsx` and `auth-simple-layout.tsx`'s left panel, so the whole
  pre-app journey — login through workspace selection — reads as one place
  instead of two similar-but-different ones. Its pulse keyframes moved to
  `app.css` (shared, matching how `synapse-row-flash` already lives there).
- **Register is a two-step sequence, not a wall of fields.** Organization +
  identity, then account + security — a real order (you can't set up the
  account before naming who it's for), so a stepper is warranted rather than
  decorative. Both panels stay mounted (just visually hidden) so a single
  `Form` submit still carries every field in one POST; a server-side error on
  a step-one field snaps the view back to it automatically.
- **The password policy is shown, not just enforced.** `lib/password-rules.ts`
  parses the same `passwordrules` string Fortify already sends
  (`Password::defaults()->toPasswordRulesString()`) into a live checklist
  (`components/password-requirements.tsx`) on register and reset-password —
  one policy, shown live, instead of duplicated as copy that could drift from
  `AppServiceProvider`'s actual rule.
- **Status messages are a real `Alert`, not a green `<div>`.** Added a
  `success` variant to `components/ui/alert.tsx` (additive) and used it for
  every status banner (login, forgot-password, verify-email).
- **Two decorative header elements did nothing.** The right-side "All Systems
  Normal" badge and notification bell on the auth shell had no data behind
  them and no handler on click — fabricated status on a screen nobody is
  signed into yet. Replaced with the existing `ThemeToggle`, which actually
  works here (reads `localStorage`/a cookie, no session required).
- **Two-factor and passkeys** got the same field treatment: bigger OTP slots
  with a teal focus-within glow on the group, an icon on the recovery-code
  field, and passkey buttons/separators restyled to match (plus a real bug fix
  — the "Or continue with email" divider chip was reading `bg-background`,
  which doesn't match the card's dark-mode override `#131929`, showing a
  seam).

## Not touched

`auth-split-layout.tsx` and `auth-card-layout.tsx` are dead code (Fortify
scaffolding, unreferenced by `app.tsx`'s layout resolver) — left alone rather
than folded into this pass.

## Verified

- `tsc --noEmit`, `eslint .` (clean, no `--fix` diffs against the touched
  files), `prettier --check` on every touched file — all green.
- `npm run build` — green.
- `pest tests/Feature/Auth` — 27/27, unaffected (frontend-only change).
- Walked every screen in a real browser (light + dark + mobile viewport):
  login, both register steps, forgot-password, and a login error state.

## Notes

- Discovered in passing, **not fixed here**: `SecurityHeaders.php`'s dev-mode
  CSP relaxation (`isRunningHot()`) adds `http://localhost:5173` to
  `script-src`/`connect-src` but not `style-src`/`font-src`, so the Vite dev
  server's injected stylesheet and fonts are silently blocked by the browser
  in local dev (production is unaffected — compiled assets are same-origin).
  Worth a follow-up; out of scope for a design pass.
