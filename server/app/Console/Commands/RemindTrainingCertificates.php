<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Support\Tenancy;
use App\Support\Training\TrainingCertificates;
use Illuminate\Console\Command;

/**
 * Certificate expiry reminders (ADR 0075), scheduled daily. A training
 * certificate that expires within {@see TrainingCertificates::EXPIRING_DAYS}
 * days is flagged once: its holder is told, and so is whoever runs training,
 * with how many are coming up. A certificate already renewed is passed over.
 */
class RemindTrainingCertificates extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'training:certificate-reminders
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Tell holders (and HR) about training certificates that expire soon';

    public function handle(Tenancy $tenancy, TrainingCertificates $certificates): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $flagged = 0;

        foreach ($organizations as $organization) {
            $flagged += $tenancy->runFor($organization, fn (): int => $certificates->sendDueReminders());
        }

        $this->info(sprintf('%d expiring %s flagged.', $flagged, str('certificate')->plural($flagged)));

        return self::SUCCESS;
    }
}
