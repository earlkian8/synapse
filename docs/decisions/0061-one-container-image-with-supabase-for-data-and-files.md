# 0061 — One container image, with Supabase for the database and the files

- **Status:** Accepted
- **Date:** 2026-10-01
- **Related:**
  - [0017 — Predictive analytics: an external ML inference service](./0017-predictive-analytics-and-ml-inference.md)
    (the service this image runs beside the app);
  - [0046 — Model graduation trains on the organisation's own records](./0046-model-graduation-trains-on-the-organisations-own-records.md)
    (the files this design cannot keep across a redeploy);
  - guide: [Deployment](../deployment.md).

## Context

Until now SYNAPSE only ran on a developer's machine: `php artisan serve`, Vite, a local
Postgres, uploads in `storage/app/public`, and the inference service started by hand
with `python -m api`. Putting it online needed answers to four questions:

1. **How many deployable pieces.** The web app needs PHP-FPM and a web server, a queue
   worker and the scheduler (attendance days close themselves hourly). The predictive
   screens need the Python inference service, which ADR 0017 made a separate process
   with its own dependencies.
2. **Where the database lives.**
3. **Where uploaded files live.** Photos, logos, résumés and documents are written to
   the `public` disk, which is a local directory.
4. **How configuration arrives.** The app reads `.env`, and a container should not ship
   one.

## Decision

**One image runs everything.** A multi-stage `Dockerfile` at the repository root builds
the Laravel app (Composer without dev packages, the Vite build) and a Python 3.14 venv
holding only what the inference service imports (`model/requirements-serve.txt`,
pinned to the training versions). In the final image `supervisord` runs nginx,
PHP-FPM, the inference service, the queue worker and the scheduler. The inference
service listens on `127.0.0.1:8001`, so only Laravel can reach it, exactly as on a
developer's machine. One image means one deploy, one URL and no service-to-service
networking to configure.

**Configuration is the environment.** The image carries production defaults
(`APP_ENV=production`, `LOG_CHANNEL=stderr`, `ML_SERVICE_URL`, `PORT=8080`) and switches
for the optional processes (`RUN_QUEUE`, `RUN_SCHEDULER`, `RUN_MIGRATIONS`). The
entrypoint refuses to start without `APP_KEY`, caches config from the runtime
environment, and runs the migrations.

**The database is Supabase Postgres**, reached through its session pooler with
`DB_SSLMODE=require` (Laravel's `pgsql` connection already reads it; the default is
`prefer`). The app already ran on Postgres, so nothing else changes.

**Uploads go to Supabase Storage when it is configured.** The `public` disk becomes an
S3 disk on Supabase's S3-compatible endpoint whenever `SUPABASE_STORAGE_BUCKET` is set,
and stays the local directory otherwise. The application code is untouched: it already
stored paths and asked the disk for URLs. Supabase has no per-object ACLs, so the
bucket itself is public.

## Consequences

- **Deploying is one image and a set of variables.** The same image runs locally with
  `docker run --env-file`.
- **The image has to be built where the models are.** `model/artifacts/` is ignored by
  git (the notebooks generate it), and the image copies the reference models from it.
  The image is built locally and pushed to a registry; a build from a clean clone
  fails.
- **An organisation's own models do not survive a redeploy.** Model graduation writes
  them into the container's filesystem, and the platform offers no persistent volume.
  The database still says the model is active, and scoring with it fails with a
  message saying it is not available on the prediction service. The general models
  are in the image and always available. Making local models durable means storing
  them somewhere shared, such as the same bucket, and is left for later.
- **One instance.** A second instance would have its own inference service and its own
  local models, and would run the scheduler a second time. Scaling out needs shared
  model storage first, and `RUN_SCHEDULER=false` on the extra instances.
- **Uploaded files are public by URL** once Supabase Storage is on, because the bucket
  must be public. This matches the local disk, which serves `storage/app/public`
  without authentication, but the files now sit on a host outside the app.
- **Without the Supabase variables, uploads are lost on redeploy**, because they land
  in the container.
- **Faker became a production dependency**, so the demo seeders can run in an image
  built without dev packages.

## Alternatives

- **Separate services for the app and the inference service.** Each could scale and
  restart on its own, and the inference service's local models could live on a volume
  of their own. The cost is two deployments and an internal URL to secure, which buys
  nothing while one instance is enough.
- **The platform's own managed Postgres.** Works the same for the database, but would
  leave uploads needing a second provider; Supabase supplies both.
- **Keeping uploads on a volume.** Not available on the target platform.
