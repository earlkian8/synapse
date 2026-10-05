<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Close job postings whose deadline has passed (recruitment due dates).
Schedule::command('recruitment:close-expired')->dailyAt('00:05');

// The end-of-day job (ADR 0041): hourly, because midnight is a different moment
// in every organisation's zone and a night shift is only over the morning after.
// Records the days nobody punched, handles forgotten clock-outs by policy, and
// sends each date's exception digest once.
Schedule::command('attendance:close-day')->hourly()->withoutOverlapping();

// Clock-in reminders (ADR 0041) for people whose shift has started.
Schedule::command('attendance:remind')->everyFifteenMinutes()->withoutOverlapping();

// Data Export (ADR 0066): delete archives past their keep-until date, and close
// exports whose build died before it finished.
Schedule::command('data-export:prune')->hourly()->withoutOverlapping();
