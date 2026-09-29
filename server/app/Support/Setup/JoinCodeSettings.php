<?php

namespace App\Support\Setup;

use App\Models\Organization;
use App\Support\ActivityLogger;

/**
 * The company join code: whether people may ask to join with it, and replacing
 * it (ADR 0059).
 *
 * The Employees → App access screen and the assistant both come through here.
 * The code itself is shown only on that screen: it lets anybody who has it
 * *ask* to join, so it stays out of chat transcripts and the model provider.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class JoinCodeSettings
{
    /**
     * Replace the code. The previous one stops working at once.
     */
    public function rotate(Organization $organization, string $channel = ''): void
    {
        $organization->rotateJoinCode();

        $this->log($organization, 'Generated a new company join code'.$channel);
    }

    /**
     * Allow or stop joining by code. False when it was already so.
     */
    public function setEnabled(Organization $organization, bool $enabled, string $channel = ''): bool
    {
        if ((bool) $organization->join_code_enabled === $enabled) {
            return false;
        }

        $organization->update(['join_code_enabled' => $enabled]);

        $this->log($organization, ($enabled ? 'Enabled joining by company code' : 'Disabled joining by company code').$channel);

        return true;
    }

    private function log(Organization $organization, string $description): void
    {
        ActivityLogger::log(
            event: 'updated',
            description: $description,
            subject: $organization,
            logName: 'company-setup',
            subjectLabel: $organization->name,
        );
    }
}
