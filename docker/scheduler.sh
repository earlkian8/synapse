#!/bin/sh
# Runs Laravel's scheduler once a minute, on the minute, without keeping a PHP
# process in memory between runs (what `artisan schedule:work` did: ~70 MB at
# idle, all day).
#
# Each run is started in the background, so a long task (attendance:close-day,
# say) never makes the next minute's run late or skipped; tasks that must not
# overlap say so with withoutOverlapping(). The orphaned run is reaped by
# supervisord, which is PID 1.
while :; do
    ( php /app/server/artisan schedule:run --no-interaction & )
    second=$(date +%S)
    sleep $((60 - ${second#0}))
done
