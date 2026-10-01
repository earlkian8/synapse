<?php

namespace App\Queries\Setup;

use App\Http\Resources\CompanyProfileResource;
use App\Models\Organization;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Company Setup → Company Profile: the tenant's own identity, the clock it keeps
 * (ADR 0036), and — for the wizard's first step — the code people join it by
 * (ADR 0026).
 */
class CompanyProfileScreen implements SetupScreen
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function toArray(Request $request): array
    {
        $organization = $this->organization($request);
        $canManage = $request->user()->can('setup.company.manage');

        return [
            'company' => (new CompanyProfileResource($organization))->resolve($request),
            'timezones' => OrganizationClock::options(),
            // The code is a credential, so it goes only to somebody who may rotate
            // it — never to a view-only visitor of the profile.
            'joinCode' => $canManage ? [
                'code' => $organization->join_code,
                'enabled' => (bool) $organization->join_code_enabled,
            ] : null,
            'can' => ['manage' => $canManage],
        ];
    }

    /**
     * The current tenant, which doubles as the company profile (ADR 0005).
     */
    private function organization(Request $request): Organization
    {
        return $this->tenancy->organization() ?? $request->user()?->defaultOrganization() ?? abort(403);
    }
}
