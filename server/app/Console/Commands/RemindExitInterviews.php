<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Support\Offboarding\ExitInterviews;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Exit-interview reminders (ADR 0076), scheduled daily. A survey that has
 * waited {@see ExitInterviews::REMIND_AFTER_DAYS} days while the exit is in
 * progress is reminded about once.
 */
class RemindExitInterviews extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'offboarding:exit-interview-reminders
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Remind leavers, once, about an exit interview still waiting for their answers';

    public function handle(Tenancy $tenancy, ExitInterviews $interviews): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $reminded = 0;

        foreach ($organizations as $organization) {
            $reminded += $tenancy->runFor($organization, fn (): int => $interviews->sendDueReminders());
        }

        $this->info(sprintf('%d %s reminded.', $reminded, str('leaver')->plural($reminded)));

        return self::SUCCESS;
    }
}
