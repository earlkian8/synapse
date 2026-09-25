<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\UpdateCompanyProfileRequest;
use App\Models\Organization;
use App\Queries\Setup\CompanyProfileScreen;
use App\Support\ActivityLogger;
use App\Support\Setup\CompanyProfileWriter;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function edit(Request $request, CompanyProfileScreen $screen): Response
    {
        return Inertia::render('setup/company', $screen->toArray($request));
    }

    /**
     * Update the company profile (identity, contact, statutory numbers, logo).
     */
    public function update(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $organization = $this->organization();

        CompanyProfileWriter::apply($organization, $request->validated());

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
        // The active tenant is bound by SetCurrentOrganization for every authed
        // request; fall back to the caller's default membership just in case.
        return $this->tenancy->organization() ?? request()->user()->defaultOrganization() ?? abort(403);
    }
}
