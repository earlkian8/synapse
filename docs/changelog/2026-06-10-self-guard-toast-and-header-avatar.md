# 2026-06-10 — Self-action toasts & header avatar

Two fixes: surface a clear toast when an admin acts on their own account, and show
the profile photo in the app header.

## 1. "You can't … your own account" toast

The backend already blocked self-archive/deactivate/delete, but the explanatory
toast did not reliably surface from the redirect round-trip, so the action just
silently no-op'd.

`resources/js/pages/system/users/index.tsx`
- Read the authenticated user id from shared `auth` props (`auth.user.id`).
- Added an `isSelf(user)` guard to the destructive row actions: **archive**,
  **deactivate** (status toggle), and **permanent delete** now short-circuit on the
  client and fire an instant `toast.error("You can't … your own account.")` instead
  of hitting the server.
- The backend guards in `UserController` / `UserStatusController` /
  `UserBulkActionController` remain as defense-in-depth (direct API & bulk).

## 2. Profile photo in the header

The header avatar always rendered initials because the shared `auth.user` had no
usable photo field (`profile_photo` is a raw storage path, and the header reads
`auth.user.avatar`).

`app/Models/User.php`
- Added an `avatar` accessor that returns the profile photo's public URL
  (`Storage::disk('public')->url(...)`) or `null`, and appended it to the model
  (`$appends = ['full_name', 'avatar']`).
- Because the header components (`app-sidebar-header`, `app-header`, `user-info`)
  already read `auth.user.avatar`, the photo now renders automatically, with the
  initials avatar as the fallback when no photo is set.

## Verification

`tsc`, ESLint and Pint clean; `npm run build` succeeds; the `avatar` accessor
returns a full URL when a photo is set and `null` otherwise (confirmed via a
bootstrap script).
