# A lighter container at idle

On DigitalOcean the container sat at 70–80% of its memory with nobody using it. Most of
that was processes waiting: PHP workers kept spare, a booted Laravel holding the
scheduler, numpy's threads, and nginx workers counted from the host's CPUs. See
[Deployment → Memory at idle](../deployment.md#memory-at-idle).

## Changes (`Dockerfile`, `docker/`)

- PHP-FPM `pm = ondemand` (workers exit after 30 s idle), at most
  `PHP_FPM_MAX_CHILDREN` (default 6), instead of a dynamic pool of 3–5 kept alive.
- `docker/scheduler.sh` runs `schedule:run` each minute from a shell loop instead of
  `schedule:work` (about 70 MB resident all day).
- `OPENBLAS_NUM_THREADS`, `OMP_NUM_THREADS`, `MKL_NUM_THREADS` = 1 and
  `MALLOC_ARENA_MAX` = 2: the ML service drops from 37 threads to 7.
- nginx runs 2 workers, not one per host CPU.
- `RUN_ML=false` leaves the ML service off (~180 MB) where the predictive screens
  aren't used.
- opcache's shared memory reserve is 128 MB (was 192).

## Notes

- Measured with local processes, not in the image: the Docker image was not built or
  run here. Check the App Platform memory graph after the next deploy.
- The queue worker stays resident: verification codes and invitations are queued mail,
  and draining the queue once a minute would make people wait for their code.
