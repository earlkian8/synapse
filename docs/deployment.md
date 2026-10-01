# Deployment

How SYNAPSE runs outside a developer's machine: **one container image** holding the
web app and the ML inference service, configured entirely by environment variables,
with **Supabase** for the Postgres database and for uploaded files. The target it was
written for is DigitalOcean App Platform, but nothing in the image depends on it. See
[ADR 0061](./decisions/0061-one-container-image-with-supabase-for-data-and-files.md).

> Files: `Dockerfile`, `.dockerignore` and `docker/` at the repository root;
> `server/config/filesystems.php` (the upload disk); `server/.env.example` (the
> variables); `model/requirements-serve.txt` (the inference service's dependencies).

## What is in the image

The build context is the repository root. Only `server/` and `model/` go in; `docs/`,
`mobile/`, the tests, the notebooks and the training data are excluded.

| Process | Command | Notes |
| --- | --- | --- |
| `ml` | `uvicorn api.main:app` on `127.0.0.1:8001` | The inference service ([Promotion Readiness](./modules/promotion-readiness.md), [Performance Forecast](./modules/performance-forecast.md), [Attrition Risk](./modules/attrition-risk.md), [Model graduation](./modules/model-graduation.md)). Bound to loopback, so only Laravel can reach it. `ML_WORKERS` workers (default 1). Starts first. |
| `php-fpm` | `php-fpm --nodaemonize` | Listens on `127.0.0.1:9000`. |
| `nginx` | `nginx -g "daemon off;"` | Listens on `$PORT` (default 8080). |
| `queue` | `php artisan queue:work --tries=3 --max-time=3600` | Off with `RUN_QUEUE=false`. |
| `scheduler` | `php artisan schedule:work` | Off with `RUN_SCHEDULER=false`. Runs the end-of-day attendance close (hourly), clock-in reminders (every 15 minutes) and closing expired job postings (daily). |

`supervisord` runs all five in the foreground and restarts any that exit. Every process
logs to the container's stdout or stderr. The queue and the scheduler keep retrying
while the database is briefly unreachable at boot.

### Build stages

1. **`php-base`** — `php:8.5-fpm-trixie` with the extensions the app uses: `bcmath`,
   `exif`, `gd`, `gmp`, `intl`, `opcache`, `pcntl`, `pdo_pgsql`, `pgsql`, `zip`.
2. **`build`** — Composer (`--no-dev`) and `npm ci` + `npm run build`. It is built on
   the PHP image because Wayfinder's Vite plugin runs `php artisan wayfinder:generate`
   during the asset build. Generated route helpers and `node_modules` are removed
   afterwards.
3. **`python`** — `uv` installs **Python 3.14**, the interpreter the models were trained
   on, into `/opt/python`, and a slim venv at `/opt/ml-venv` from
   `requirements-serve.txt`. That file pins the same versions as `requirements.txt`, so
   the `joblib` artifacts load exactly as trained, but leaves out the notebook tooling.
4. **final** — `php-base` plus nginx, supervisor and curl, the production `php.ini`
   with `docker/php.ini` on top, the built app, the venv, and `model/api`,
   `model/synapse_ml` and `model/artifacts`.

### Runtime settings worth knowing

- **Uploads:** nginx accepts 55 MB bodies; PHP allows 50 MB files and 55 MB posts.
- **Long requests:** training an organisation's own model happens inside a web request
  (`ML_SERVICE_TRAIN_TIMEOUT`, 300 s), so PHP-FPM's `request_terminate_timeout` is 600 s
  and nginx's FastCGI read timeout matches.
- **OPcache** never re-checks files (`validate_timestamps = 0`): the code cannot change
  inside a running container.
- **Only `index.php` executes.** Any other `.php` path is a 404, and dotfiles are denied.
  Vite's hashed assets under `/build/` are cached for good.
- **`clear_env = no`** in PHP-FPM, so the container's environment reaches the app.
- **Health check:** `GET /up` every 30 s, after a 60 s start period.

## Building and running

```bash
# From the repository root, on a machine that has trained model artifacts:
docker build -t earlkian8/synapse:latest .
docker run --env-file server/.env.production -p 8080:8080 earlkian8/synapse:latest
```

**The model artifacts are not in git.** `model/artifacts/` is ignored (the notebooks
generate it, and the training data is personal data that is never committed), but the
image copies the reference models from it. So the image must be built from a working
tree where the notebooks have been run, not from a fresh clone. That means a CI or
App Platform build from the repository alone would fail at
`COPY model/artifacts`. Build locally and push the image to a registry, then deploy
the image.

### What the entrypoint does

`docker/entrypoint.sh` runs before `supervisord`:

1. Refuses to start without `APP_KEY` (generate one with
   `php artisan key:generate --show`).
2. Normalises `RUN_QUEUE`, `RUN_SCHEDULER` and `RUN_MIGRATIONS` to `true` or `false`.
3. Writes `$PORT` into the nginx config.
4. Creates the writable `storage/` and `bootstrap/cache` directories, in case they
   arrive on an empty volume, and hands them to `www-data`.
5. `php artisan optimize`, which caches config, routes, views and events from the
   runtime environment. Not `optimize:clear`: that would also empty the database cache
   store, whose table does not exist before the first migration.
6. `php artisan migrate --force`, unless `RUN_MIGRATIONS=false`.

## Configuration

The image sets these defaults; anything set on the platform overrides them:

| Variable | Default in the image |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `info` |
| `ML_SERVICE_URL` | `http://127.0.0.1:8001` |
| `PORT` | `8080` |
| `RUN_MIGRATIONS` / `RUN_QUEUE` / `RUN_SCHEDULER` | `true` |
| `ML_WORKERS` | `1` |

Set these yourself (see `server/.env.example` for the full list):

| Group | Variables |
| --- | --- |
| App | `APP_KEY`, `APP_URL`, `TRUSTED_PROXIES` (the platform's load balancer, or `*`, so HTTPS and client IPs are read from forwarded headers) |
| Database | `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` |
| Files | the `SUPABASE_STORAGE_*` variables (below) |
| Mail | `MAIL_*` — verification codes, invitations and notifications are sent by email |
| Web push | `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` |
| Assistant | `GEMINI_API_KEY`, optionally `GEMINI_MODEL` |

Sessions, the cache and the queue use the database (`SESSION_DRIVER`, `CACHE_STORE`,
`QUEUE_CONNECTION` = `database`), so the app needs no Redis.

## Supabase

### Database

Use the connection from **Supabase Dashboard → Connect → Session pooler**:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=aws-0-<region>.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.<project-ref>
DB_PASSWORD=…
DB_SSLMODE=require
```

`DB_SSLMODE` is read by `config/database.php` (default `prefer`). The schema is
created by the entrypoint's `migrate --force` on first boot. `server/.env.example`
carries a local block and a commented Supabase block; keep exactly one uncommented.

### Storage

Every upload — profile and employee photos, company logos, résumés, applicant and
employee documents, certification files, punch selfies — goes through the `public`
disk. When `SUPABASE_STORAGE_BUCKET` is set, `config/filesystems.php` points that disk
at **Supabase Storage** through its S3-compatible API
(`league/flysystem-aws-s3-v3`). Otherwise it is `storage/app/public`, served through
the `public/storage` link.

```dotenv
SUPABASE_STORAGE_KEY=…        # Dashboard → Storage → S3 Connection
SUPABASE_STORAGE_SECRET=…
SUPABASE_STORAGE_REGION=…
SUPABASE_STORAGE_BUCKET=…
SUPABASE_STORAGE_ENDPOINT=https://<project-ref>.storage.supabase.co/storage/v1/s3
SUPABASE_STORAGE_URL=https://<project-ref>.supabase.co/storage/v1/object/public/<bucket>
```

- The bucket must be marked **Public**: Supabase has no per-object ACLs, so the disk
  sets `retain_visibility => false` and relies on the bucket. Every stored file is
  therefore readable by anyone who has its URL.
- The disk uses path-style endpoints, and does not throw or report on a failed write
  (the same as the local disk).
- Nothing in the code names Supabase: models store a path and ask the disk for its URL,
  so switching between local files and the bucket needs no code change. Files already
  stored on one are not copied to the other.

## Things that do not survive a redeploy

The container's filesystem is thrown away on every deploy, and App Platform offers no
persistent volume. Two things are written there:

1. **Uploads, when Supabase Storage is off.** They land in `storage/app/public`
   inside the container. In production, set the `SUPABASE_STORAGE_*` variables.
2. **Each organisation's own trained models.** Model graduation
   ([ADR 0046](./decisions/0046-model-graduation-trains-on-the-organisations-own-records.md))
   saves them to `/app/model/artifacts/local/<tenant>/…`. The `local_models` row in the
   database outlives the file. After a redeploy, scoring with an organisation's own
   model fails with a message saying the model is not available on the prediction
   service and suggesting a switch back to the general model. The general models ship
   inside the image and are unaffected. To recover, switch to the general model and
   train again.

### Running more than one instance

Each instance has its own inference service and its own copy of `artifacts/local`, so
a model trained on one instance is not on the others. Run **one instance**, or put
`model/artifacts/local` on shared storage first. If you do scale out, run the
scheduler on one instance only (`RUN_SCHEDULER=false` on the rest).

## The mobile app

The [mobile app](./modules/mobile-app.md) talks to the same server. Point it at the
deployment with `EXPO_PUBLIC_API_URL=https://<your-domain>/api` in `mobile/.env`.

## Demo data

`fakerphp/faker` is a production dependency (it moved from `require-dev`), because the
seeders use it and the image installs Composer packages with `--no-dev`. So the demo
workspace can be seeded inside a running container:

```bash
php artisan db:seed --force
```
