<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Support\Events\EventWorkflow;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Event reminders that go out on their own (ADR 0070), scheduled every five
 * minutes. An event set to remind people `reminder_minutes` before it starts
 * tells every invitee who has not declined, once ({@see EventWorkflow::sendDueReminders()}).
 *
 * The reminder is an ordinary notification (in the app, by email and by web push,
 * as the person chose). The mobile app has no push channel of its own yet.
 */
class RemindEvents extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'events:remind
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Remind invitees of events that start soon';

    public function handle(Tenancy $tenancy, EventWorkflow $workflow): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $sent = 0;

        foreach ($organizations as $organization) {
            $sent += $tenancy->runFor($organization, fn (): int => $workflow->sendDueReminders());
        }

        $this->info(sprintf('%d %s sent.', $sent, str('reminder')->plural($sent)));

        return self::SUCCESS;
    }
}
