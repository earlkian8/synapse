<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AccountResource;
use App\Http\Resources\Admin\AdminActivityResource;
use App\Http\Resources\Admin\OrganizationResource;
use App\Http\Resources\Admin\ProblemReportResource;
use App\Models\AdminActivityLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\ProblemReport;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Queries\Admin\AccountsIndexQuery;
use App\Queries\Admin\OrganizationsIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal's first screen (ADR 0085): the platform in a few numbers, the
 * newest organisations, the latest ERP sign-ins, the problem reports still
 * waiting on an admin (ADR 0096) — and, for a super admin, the latest admin
 * activity.
 */
class OverviewController extends Controller
{
    public function __invoke(Request $request, OrganizationsIndexQuery $organizations, AccountsIndexQuery $accounts): JsonResponse
    {
        $organizationStats = $organizations->stats();
        $accountStats = $accounts->stats();

        return response()->json([
            'stats' => [
                'organizations' => $organizationStats['total'],
                'organizations_this_month' => $organizationStats['this_month'],
                'in_setup' => $organizationStats['in_setup'],
                'accounts' => $accountStats['total'],
                'active_30d' => $accountStats['active_30d'],
                'unverified' => $accountStats['unverified'],
                'employees' => Employee::query()->withoutGlobalScope(OrganizationScope::class)->count(),
                'mobile_sessions' => $accountStats['mobile_sessions'],
                'open_reports' => ProblemReport::query()->open()->count(),
                'new_reports' => ProblemReport::query()->where('status', 'new')->count(),
            ],
            'open_reports' => ProblemReportResource::collection(
                ProblemReport::query()->open()->with(['user', 'organization'])->withCount('attachments')->latest('id')->limit(5)->get(),
            ),
            'newest_organizations' => OrganizationResource::collection(
                OrganizationsIndexQuery::withMeasures(Organization::query())->latest()->limit(5)->get(),
            ),
            'recent_sign_ins' => AccountResource::collection(
                User::query()->withCount('memberships')->whereNotNull('last_login_at')->latest('last_login_at')->limit(5)->get(),
            ),
            'recent_activity' => $request->user()->isSuperAdmin()
                ? AdminActivityResource::collection(AdminActivityLog::query()->latest('id')->limit(6)->get())
                : null,
        ]);
    }
}
