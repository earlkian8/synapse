<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\UpdateCompanyProfileRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\Organization;
use App\Support\ActivityLogger;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Profile (Company Setup) — the editable identity, contact details and
 * statutory employer numbers of the organisation. The tenant's `organizations`
 * row *is* the company profile (ADR 0005), so this edits the current tenant.
 */
class CompanyProfileController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Show the company profile.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('setup/company', [
            'company' => (new CompanyProfileResource($this->organization()))->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.company.manage')],
        ]);
    }

    /**
     * Update the company profile (identity, contact, statutory numbers, logo).
     */
    public function update(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $organization = $this->organization();

        // Logo: remove first (explicit), then a new upload supersedes the old file.
        if ($request->boolean('remove_logo') && $organization->logo) {
            Storage::disk('public')->delete($organization->logo);
            $organization->logo = null;
        }

        if ($request->hasFile('logo')) {
            if ($organization->logo) {
                Storage::disk('public')->delete($organization->logo);
            }

            $organization->logo = $request->file('logo')->store('organization-logos', 'public');
        }

        $organization->fill(Arr::except($request->validated(), ['logo', 'remove_logo']));
        $organization->save();

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated the company profile',
            subject: $organization,
            logName: 'company-setup',
            subjectLabel: $organization->name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Company profile updated.']);

        return back();
    }

    /**
     * The current tenant — the organisation that doubles as the company profile.
     */
    private function organization(): Organization
    {
        return $this->tenancy->organization() ?? Organization::findOrFail(request()->user()->organization_id);
    }
}
