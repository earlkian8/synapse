<?php

namespace App\Support\Setup;

use App\Http\Controllers\Setup\CompanyProfileController;
use App\Http\Controllers\Setup\SetupWizardController;
use App\Http\Requests\Setup\UpdateCompanyProfileRequest;
use App\Models\Organization;
use App\Services\Assistant\Modules\CompanyProfileModule;
use App\Support\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/**
 * Applies an edit to the company profile — the identity, contact details,
 * statutory numbers and logo the tenant doubles as (ADR 0005).
 *
 * Two screens write it: {@see CompanyProfileController} and the first step of
 * {@see SetupWizardController}. The logo has rules that are easy to get subtly
 * wrong on a second implementation (remove before replace, delete the file the
 * old row pointed at, never leave an orphan on disk), so both call this instead
 * of each carrying its own copy. Input is whatever
 * {@see UpdateCompanyProfileRequest} validated.
 *
 * {@see save()} is the Company Profile screen's edit, recorded — and the
 * assistant's ({@see CompanyProfileModule}), which appends its channel
 * (" via assistant") to the audit line.
 */
class CompanyProfileWriter
{
    /**
     * Apply an edit and record it. A changed time zone is named in the audit
     * line, old and new: it moves the clock every attendance day is judged on
     * (ADR 0036), and "updated the company profile" would hide that.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function save(Organization $organization, array $validated, string $channel = ''): void
    {
        $zone = $organization->timezone;

        self::apply($organization, $validated);

        ActivityLogger::log(
            event: 'updated',
            description: $organization->timezone !== $zone
                ? "Updated the company profile{$channel}: time zone {$zone} → {$organization->timezone}"
                : "Updated the company profile{$channel}",
            subject: $organization,
            logName: 'company-setup',
            subjectLabel: $organization->name,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function apply(Organization $organization, array $validated): void
    {
        // Removal first (it is explicit), then an upload supersedes whatever is
        // left — so "remove and replace in one save" ends with the new file.
        if (($validated['remove_logo'] ?? false) && $organization->logo) {
            Storage::disk('public')->delete($organization->logo);
            $organization->logo = null;
        }

        $logo = $validated['logo'] ?? null;

        if ($logo instanceof UploadedFile) {
            if ($organization->logo) {
                Storage::disk('public')->delete($organization->logo);
            }

            $organization->logo = $logo->store('organization-logos', 'public');
        }

        $organization->fill(Arr::except($validated, ['logo', 'remove_logo']));
        $organization->save();
    }
}
