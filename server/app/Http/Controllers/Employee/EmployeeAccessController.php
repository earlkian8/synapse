<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeInvitationResource;
use App\Http\Resources\OrganizationJoinRequestResource;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\OrganizationJoinRequest;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App Access — who on the roster can actually sign in, and who is waiting to
 * (ADR 0026).
 *
 * Since hiring stopped creating logins, "does this employee have the app?" became
 * a question with its own answer rather than a side effect of the 201 record. This
 * screen is that answer, in three columns: people at the door (join requests),
 * invitations still outstanding, and roster lines nobody has been invited to yet.
 */
class EmployeeAccessController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function index(Request $request): Response
    {
        $organization = $this->tenancy->organization();

        $requests = OrganizationJoinRequest::query()
            ->pending()
            ->with(['user', 'organization'])
            ->oldest('id')
            ->get();

        $invitations = EmployeeInvitation::query()
            ->outstanding()
            ->with(['employee.position', 'employee.department', 'inviter'])
            ->latest('id')
            ->get();

        // Every roster line nobody has claimed. This one list serves two jobs: the
        // ones with no invitation are the backlog to burn down, and all of them
        // are the candidates HR picks from when approving a join request.
        // `invitations` is eager-loaded so appAccess() stays off the N+1 path.
        $unlinked = Employee::query()
            ->whereNull('user_id')
            ->with(['invitations', 'position', 'department'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return Inertia::render('employees/access', [
            'requests' => OrganizationJoinRequestResource::collection($requests)->resolve($request),
            'invitations' => EmployeeInvitationResource::collection($invitations)->resolve($request),
            'unlinked' => $unlinked->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'email' => $employee->email,
                'initials' => $employee->initials(),
                'photo' => $employee->photo_url,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
                'app_access' => $employee->appAccess(),
            ])->all(),
            'joinCode' => [
                'code' => $organization?->join_code,
                'enabled' => (bool) $organization?->join_code_enabled,
            ],
            'can' => [
                'invite' => $request->user()->can('employees.invite'),
                'manageJoinCode' => $request->user()->can('setup.company.manage'),
            ],
        ]);
    }
}
