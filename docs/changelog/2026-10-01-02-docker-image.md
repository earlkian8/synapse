# One Docker image for the whole system

SYNAPSE now builds into a single container image that runs the web app, the ML
inference service, the queue worker and the scheduler, configured entirely by
environment variables. It was written for DigitalOcean App Platform and runs the same
anywhere Docker does. See
[ADR 0061](../decisions/0061-one-container-image-with-supabase-for-data-and-files.md)
and [Deployment](../deployment.md).

## Highlights

- **One image, five processes.** `supervisord` runs nginx, PHP-FPM, the inference
  service (uvicorn on `127.0.0.1:8001`, so only Laravel reaches it), `queue:work` and
  `schedule:work`. The queue and the scheduler can be switched off with `RUN_QUEUE` and
  `RUN_SCHEDULER`.
- **It configures itself on boot.** The entrypoint refuses to start without `APP_KEY`,
  writes `$PORT` into nginx, prepares `storage/`, caches config, routes, views and
  events, and runs the migrations (unless `RUN_MIGRATIONS=false`).
- **The models load as trained.** The inference service runs on Python 3.14 with
  `model/requirements-serve.txt`, the runtime subset of `requirements.txt` at the same
  pinned versions, so the `joblib` artifacts load exactly as they were saved.
- **A health check.** `GET /up` every 30 seconds.

## Added

- `Dockerfile`: stages `php-base` (PHP 8.5 FPM with `bcmath exif gd gmp intl opcache
  pcntl pdo_pgsql pgsql zip`), `build` (Composer `--no-dev`, `npm ci`, `npm run build`
  on the PHP image because Wayfinder's Vite plugin runs artisan), `python` (`uv`,
  Python 3.14, a venv at `/opt/ml-venv`) and the final image.
- `.dockerignore`: only `server/` and `model/` go in. Docs, the mobile app, tests,
  notebooks, training data, `.env` files and local models are left out.
- `docker/entrypoint.sh`, `docker/supervisord.conf`, `docker/nginx.conf` (55 MB
  bodies, immutable `/build/` assets, only `index.php` executes, 600 s FastCGI
  timeout), `docker/php-fpm.conf` (`clear_env = no`, `request_terminate_timeout = 600`
  for model training) and `docker/php.ini` (512 MB memory, 50 MB uploads, OPcache that
  never re-checks files).
- `model/requirements-serve.txt`.

## Changed

- `server/composer.json`: `fakerphp/faker` moved from `require-dev` to `require`, so
  the seeders can run in an image built without dev packages.

## Notes

- **Build where the models are.** `model/artifacts/` is ignored by git, but the image
  copies the reference models from it. Build on a machine where the notebooks have
  been run, push the image, and deploy the image. A build from a fresh clone fails at
  `COPY model/artifacts`.
- **An organisation's own models are lost on redeploy.** Model graduation saves them in
  the container. Scoring with one afterwards fails with a message to switch back to
  the general model; retraining restores it.
- **Uploads are lost on redeploy too**, unless Supabase Storage is configured
  ([2026-10-01-01](./2026-10-01-01-supabase-database-and-storage.md)).
- **Run one instance.** Each instance has its own inference service and local models,
  and its own scheduler.
