<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\Admin\AdminAudit;
use Illuminate\Console\Command;

/**
 * Break-glass for a lost phone (ADR 0085): forget an admin's authenticator and
 * end their sessions, for when no other super admin can do it from the portal.
 */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:reset-two-factor
        {email : The admin\'s email address}
        {--force : Skip the confirmation}';

    protected $description = 'Remove an admin\'s authenticator so they set up a new one at their next sign-in';

    public function handle(): int
    {
        $admin = Admin::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($admin === null) {
            $this->error('No admin has that email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Reset the authenticator of {$admin->name} ({$admin->email}) and sign them out everywhere?")) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $ended = $admin->revokeSessions();

        AdminAudit::record('admin_two_factor_reset', "Reset {$admin->name}'s authenticator at the server console", subject: $admin, properties: ['sessions_ended' => $ended, 'via' => 'console'], subjectLabel: "{$admin->name} ({$admin->email})");

        $this->info("{$admin->email} will set up a new authenticator at their next sign-in.");

        return self::SUCCESS;
    }
}
