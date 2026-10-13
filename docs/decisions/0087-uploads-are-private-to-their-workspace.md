# 0087 — Uploads are private to their workspace

- **Status:** Accepted
- **Date:** 2026-10-14
- **Related:**
  - [0005 — Multi-tenancy](./0005-multi-tenancy.md) (the tenant scope every access
    check here runs through);
  - [0061 — One container image](./0061-one-container-image-with-supabase-for-data-and-files.md)
    (Supabase Storage for uploads; this reverses its public bucket);
  - [0066 — Data export](./0066-data-export-a-copy-of-the-workspace-not-a-backup.md) and
    [0068 — The assistant finishes the job](./0068-the-assistant-finishes-the-job.md)
    (the private buckets already used for archives and chat files);
  - [deployment](../deployment.md#storage).

## Context

Every upload went to one Supabase bucket marked **Public**, and the app linked straight
to it (`…/storage/v1/object/public/<bucket>/applicant-resumes/<random>.pdf`). That
covered résumés, applicant and employee documents, certification files, clock-in
selfies, and employee and profile photos. The random file name was the only thing
protecting a file. Anybody who had a link could open it with no account at all: a
pasted URL, a browser history, a log line, or HR from another company. SYNAPSE is
multi-tenant SaaS, and these files are personal data under the Data Privacy Act.

A second problem came from the same setup: the app's Content-Security-Policy allows
images only from its own origin. So in production, photos and the company logo served
from the Supabase domain did not display at all.

## Decision

**The bucket is private, and the app is the only way a file leaves it.**

- **One disk, `uploads`.** It is the same Supabase bucket, the same S3 keys and the
  same stored paths, now set to private, so nothing had to move. Without Supabase it is
  `storage/app/private/uploads`. The `public` disk is no longer used, and the `local`
  disk's `/storage/{path}` serve route is turned off.
- **Links point at the app.** `Uploads::url()` builds every link: `/files/{path}` for
  the web app, which reads the session, and `/api/files/{path}` for the mobile app,
  which reads the token. The mobile app sends its token on these image requests, and
  only to its own server (`fileSource()` in `mobile/lib/api.ts`).
- **`UploadAccess` decides who may open a file.** It finds the record holding the path
  through that model's tenant scope, so another workspace's file is never found. Then
  it asks for the permission the screen showing that record asks for:
  - résumés and applicant documents: `recruitment.view`;
  - employee documents and certifications: `employees.view`, or the asker's own;
  - clock-in photos: `attendance.view`, or the asker's own punch;
  - employee photos: anybody in the workspace (the directory, roster and recognition
    show them);
  - profile photos: the person, or anybody sharing the workspace with them.

  With no workspace bound it refuses everything but the asker's own profile photo,
  because the tenant scope switches itself off when no tenant is bound.
- **Every refusal is a 404**, so a path cannot be probed for whether it exists. Only
  the known upload folders are ever served, and `..`, absolute and backslash paths are
  rejected.
- **Only safe types are shown in the page.** PNG, JPEG, GIF, WebP and PDF are served
  inline, with the type taken from the stored name rather than the uploader's claim.
  Anything else downloads as `application/octet-stream`, so an uploaded HTML or SVG
  file never runs as one of the app's pages.
- **Caching.** Responses are `private, no-cache` with `Last-Modified`. The browser
  checks with the server on every use, and the access check runs again before any 304.
  A copy left behind after signing out cannot be reopened.
- **Logos are the one public file.** The careers page and the invitation page show a
  company logo to people who are signed out, so `/logos/{file}` serves the
  `organization-logos` folder to anybody, and nothing outside it.

## Consequences

- A leaked link is useless to anyone not signed in to the right workspace with the
  right permission. HR in another company gets a 404 for every file of yours.
- Photos and logos display again, because they now come from the app's own origin.
- Each file costs one storage metadata read plus the download, streamed through PHP.
  Unchanged files revalidate with a 304.
- Old public links (`/object/public/…`) stopped working when the bucket was made
  private. Pages build new links on every load, so nothing stored needs changing.
- Local development without Supabase now stores in `storage/app/private/uploads`.
  Files under `storage/app/public` from before are not moved automatically.
