# Supabase for the database and the uploaded files

SYNAPSE can now run against a hosted Supabase project: its Postgres database through
the session pooler, and Supabase Storage for every uploaded file. Both are switched on
by environment variables alone. Without them, the app behaves exactly as before: a
local Postgres and files in `storage/app/public`. See
[ADR 0061](../decisions/0061-one-container-image-with-supabase-for-data-and-files.md)
and [Deployment](../deployment.md).

## Highlights

- **Uploads can live in Supabase Storage.** Photos, logos, résumés, documents,
  certification files and punch selfies all go through the `public` disk. When
  `SUPABASE_STORAGE_BUCKET` is set, that disk talks to Supabase's S3-compatible API;
  otherwise it is the local directory, as before.
- **No application code changed.** Models already stored a path and asked the disk for
  a URL, so they work on either.
- **The database is a configuration choice.** `server/.env.example` now carries a local
  block and a commented Supabase block, and documents `DB_SSLMODE`.

## Backend

- `config/filesystems.php`: the `public` disk is an `s3` disk when
  `SUPABASE_STORAGE_BUCKET` is set (`SUPABASE_STORAGE_KEY`, `_SECRET`, `_REGION`,
  `_BUCKET`, `_ENDPOINT`, `_URL`), with path-style endpoints and
  `retain_visibility => false`, because Supabase has no per-object ACLs and the bucket
  itself must be public. It falls back to the local disk otherwise.
- `composer.json`: `league/flysystem-aws-s3-v3` ^3.0, for the S3 driver.
- `.env.example`: `DB_SSLMODE=prefer` in the local block; a commented Supabase block
  (session-pooler host, `postgres.<project-ref>` user, `DB_SSLMODE=require`); the
  `SUPABASE_STORAGE_*` variables with where to find each in the Supabase dashboard.

## Notes

- **The bucket is public.** Anyone with a file's URL can read it, as with the local
  `storage/app/public`, but the files now sit outside the app's host.
- **Existing files are not moved.** Switching the disk does not copy what is already
  stored on the other one.
- `mobile/package-lock.json` was deleted in the same commit and is not in `.gitignore`,
  so the mobile app currently has no lockfile and `npm ci` cannot run there.
  `server/package-lock.json` was refreshed.
