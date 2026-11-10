<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Support\CheckIns\CheckInReminders;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Check-in nudges (ADR 0102), scheduled daily: on the 1st of each organisation's
 * month the check-in opens, and on the 20th whoever hasn't answered is reminded.
 */
class RemindCheckIns extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'check-ins:remind
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Tell people this month\'s check-in is open, and remind whoever has not answered on the 20th';

    public function handle(Tenancy $tenancy, CheckInReminders $reminders): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $sent = 0;

        foreach ($organizations as $organization) {
            $sent += $tenancy->runFor($organization, fn (): int => $reminders->sendDue());
        }

        $this->info(sprintf('%d %s reminded.', $sent, str('person')->plural($sent)));

        return self::SUCCESS;
    }
}
