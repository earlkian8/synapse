# Uploads are private to their workspace

Every upload sat in a public Supabase bucket, so anybody with a link could open a
résumé, a contract or a clock-in selfie without signing in. The bucket is now private.
The app streams a file only to somebody signed in to the workspace it belongs to, who
may see its record. Photos and the company logo display again: the app's
Content-Security-Policy had been blocking them from the Supabase domain. See
[ADR 0087](../decisions/0087-uploads-are-private-to-their-workspace.md).

## Highlights

- HR from another workspace, or anybody signed out, gets a 404 for every file.
- Résumés need recruitment access. Employee documents and certifications need employee
  access, or must be your own. Clock-in photos need attendance access, or must be your
  own punch. Photos are visible to the workspace.
- Company logos stay public through `/logos/{file}` (the careers page needs them).

## Backend

- `config/filesystems.php`: a private `uploads` disk (same bucket, keys and paths;
  local fallback `storage/app/private/uploads`). The `public` disk is plain local and
  unused. The `local` disk's `/storage/{path}` route is off.
- `App\Support\Files\Uploads`: the disk, the upload folders, `url()` (web or API link),
  `servable()`, `inlineType()`.
- `App\Support\Files\UploadAccess`: the per-folder, tenant-scoped access check.
- `FileController` with routes `files.show` (web, `auth`), `api.files.show`
  (`auth:sanctum`) and `files.logo` (public). Every refusal is a 404. Inline only for
  PNG, JPEG, GIF, WebP and PDF; anything else downloads. `private, no-cache` with
  `Last-Modified` and 304.
- Every controller, model accessor, resource and support class that stored, read or
  linked a file now uses `Uploads`. That covers résumés, applicant and employee
  documents, certifications, punches, photos, logos, the hirer, data export, AI
  candidate insights, offboarding documents and trash.

## Mobile

- `lib/api.ts`: `fileSource()` sends the token on image requests to the app's own
  server. Used by `Avatar` and the clock-in selfie on the attendance day screen.

## Tests

- `tests/Feature/Files/UploadAccessTest.php` (19): own workspace vs another, each
  permission, signed out, path traversal and unknown folders, download-only types,
  304 after the check, public logos, the app's token route, no workspace bound, links
  never pointing at the bucket.
- Existing tests fake the `uploads` disk instead of `public`.

## Notes

- Pest 2,009/2,009 (serial, local Postgres). Pint, tsc, ESLint and `npm run build`
  pass, and mobile tsc and ESLint pass. Over HTTP against the now-private bucket: the
  logo route returned the JPEG, and a signed-out résumé request redirected to login
  (401 on the API). Docker image not built.
- Local files under `storage/app/public` (from before Supabase) are not moved.
