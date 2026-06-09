# 2026-06-10 — Profile photos, email verification & toast styling

Enhancements to the [User Management](../modules/user-management.md) form plus a
global toast-styling pass.

## Summary

- **Profile photo upload** in the add/edit form (preview, change, remove), stored on
  the `public` disk and served via a full URL.
- **Email-verified toggle** in the add/edit form — an admin can mark an account
  verified without the confirmation email.
- **Removed the Employee ID input** from the add/edit form (the column and its
  table/detail display remain).
- **Colour-coded toasts** — success / error / warning / info now carry a restrained,
  professional accent.

---

## 1. Profile photo upload

**Frontend** — `resources/js/features/users/components/user-form-sheet.tsx`
- Avatar preview block at the top of the form: circular preview (falls back to
  initials), **Upload / Change** and **Remove** buttons, a hidden `file` input
  (`image/jpeg,png,webp`), size hint and inline validation error.
- Object-URL previews are revoked on change to avoid leaks.
- Form submits with `forceFormData: true` so the request is multipart and Inertia
  spoofs the `PATCH` method on edit.

**Backend**
- `StoreUserRequest` / `UpdateUserRequest`: added `photo`
  (`nullable, image, mimes:jpg,jpeg,png,webp, max:2048`); `UpdateUserRequest` also
  adds `remove_photo` (boolean).
- `UserController@store|update`: stores the upload under `profile-photos/` on the
  `public` disk; deletes the old file on replace / explicit remove / permanent
  delete (`deletePhoto()` helper).
- `UserResource`: `profile_photo` now returns a full URL via
  `Storage::disk('public')->url(...)`.
- Ran `php artisan storage:link` (one-time) so files are web-accessible.

> **Production note:** photos use the `public` (local) disk. For production, swap the
> disk argument in `store('profile-photos', '<disk>')` and the resource URL to an
> object store (e.g. S3).

## 2. Email verification toggle

- Form: an **Email verified** switch in the *Access* section (create + edit).
- `StoreUserRequest` / `UpdateUserRequest`: added `email_verified` (boolean).
- `UserController@store`: sets `email_verified_at = now()` when enabled.
- `UserController@update`: the toggle is **authoritative** — it sets or clears
  `email_verified_at` and overrides the previous "email changed ⇒ re-verify" reset.

## 3. Employee ID input removed

- Removed the field and its data binding from `user-form-sheet.tsx`.
- Removed the `employee_id` rules from `StoreUserRequest` / `UpdateUserRequest`.
- `employee_id` is **still displayed** in the table and detail drawer; it is now
  provisioned outside this form (HR/employee module).

## 4. Toast styling

- `resources/css/app.css`: per-status styling for Sonner toasts via
  `[data-sonner-toast][data-type='…']` — a tinted card
  (`color-mix(... 8%, popover)`), a 3px coloured left accent bar, and a coloured
  icon. Palette: success `#10b981`, error `#ef4444`, warning `#f59e0b`,
  info `#0abfbf` (brand teal). Works in light and dark themes.
- `resources/js/components/ui/sonner.tsx`: enabled `closeButton`.
- Toasts continue to be driven by `Inertia::flash('toast', ['type' => …, 'message' => …])`
  → `use-flash-toast` → `toast[type](message)`.

## Tests

`tests/Feature/UserManagement/UserManagementTest.php` gained:
- create-as-verified,
- verify / unverify on update,
- profile-photo upload (`UploadedFile::fake()` + `Storage::fake('public')`).

(Feature suite needs `pdo_sqlite` / CI; DB-free unit tests pass locally.)

## Verification

`tsc`, ESLint, and Pint all clean; `npm run build` succeeds; backend behaviour
(verified flag, photo URL) confirmed via a bootstrap script.
