<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Notifications\JoinRequestDecisionNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The join-code half of getting into a workspace (ADR 0026) — Google Classroom's
 * class code, with one concession to the fact that this is an HR system.
 *
 * Typing a valid code does not, on its own, admit anybody. It resolves like this:
 *
 * - **The code matches an unclaimed roster line's email exactly** → they are that
 *   person, so admit them immediately and bind the line. This is the ordinary case
 *   and it feels instant, which is the point.
 * - **Anything else** → an {@see OrganizationJoinRequest} that HR reviews and binds
 *   by hand. A leaked code therefore buys a stranger a place in a queue, not a
 *   seat in somebody's workforce.
 *
 * Like {@see EmployeeInvitations}, the candidate-facing lookups here run
 * `withoutGlobalScopes()` on purpose: the code is answered from outside the tenant
 * that owns it, by somebody who is not yet a member.
 */
class WorkspaceJoin
{
    /**
     * What a join attempt did: admitted on the spot, or queued for review.
     */
    public const ADMITTED = 'admitted';

    public const PENDING = 'pending';

    /**
     * Attempt to join the organisation behind a typed code.
     *
     * @return array{status: string, organization: Organization, request: ?OrganizationJoinRequest}
     *
     * @throws RuntimeException when the code is unknown or they are already in.
     */
    public static function join(?string $code, User $user): array
    {
        $organization = Organization::findByJoinCode($code);

        if ($organization === null) {
            throw new RuntimeException('That join code is not valid. Check it with your HR team.');
        }

        if ($user->isMemberOf($organization)) {
            throw new RuntimeException("You are already a member of {$organization->name}.");
        }

        return DB::transaction(function () use ($organization, $user): array {
            $match = self::rosterMatch($organization, $user);

            if ($match !== null) {
                OrganizationProvisioner::admit($organization, $user, $match);

                // A pending request from before (they asked, then HR added them to
                // the roster) is settled by the same act.
                self::existing($organization, $user)?->forceFill([
                    'status' => OrganizationJoinRequest::APPROVED,
                    'employee_id' => $match->id,
                    'reviewed_at' => now(),
                ])->save();

                app(Tenancy::class)->runFor($organization, fn () => ActivityLogger::log(
                    event: 'updated',
                    description: "{$user->full_name} joined with the company code and was matched to {$match->full_name}",
                    subject: $match,
                    logName: 'employees',
                    subjectLabel: $match->full_name,
                ));

                return ['status' => self::ADMITTED, 'organization' => $organization, 'request' => null];
            }

            // No line to bind — queue them. Re-asking after a decline revives the
            // same row, so the review screen lists people rather than attempts.
            $request = self::existing($organization, $user);

            if ($request !== null && $request->status === OrganizationJoinRequest::PENDING) {
                throw new RuntimeException("You've already asked to join {$organization->name}. Your HR team will review it.");
            }

            $request = $request ?? new OrganizationJoinRequest([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);

            $request->forceFill([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'status' => OrganizationJoinRequest::PENDING,
                'employee_id' => null,
                'decline_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            // `toRole` resolves the role by name, and roles are per-organisation —
            // so bind the tenant it belongs to, since the person asking is by
            // definition not inside it yet.
            app(Tenancy::class)->runFor($organization, fn () => Notifier::toRole(
                'hr-manager',
                'Someone wants to join',
                "{$user->full_name} ({$user->email}) used the company join code and is waiting to be matched to an employee record.",
                url: '/employees/access',
                level: 'info',
                category: 'employees',
            ));

            return ['status' => self::PENDING, 'organization' => $organization, 'request' => $request];
        });
    }

    /**
     * Approve a pending request, binding it to a roster line HR nominates.
     *
     * @throws RuntimeException when the line is taken or belongs elsewhere.
     */
    public static function approve(OrganizationJoinRequest $request, Employee $employee, User $reviewer): void
    {
        if ($request->status !== OrganizationJoinRequest::PENDING) {
            throw new RuntimeException('This request has already been reviewed.');
        }

        if ($employee->organization_id !== $request->organization_id) {
            throw new RuntimeException('That employee belongs to another organisation.');
        }

        if ($employee->user_id !== null) {
            throw new RuntimeException("{$employee->full_name} already has app access.");
        }

        $user = $request->user;
        $organization = $request->organization;

        if ($user === null || $organization === null) {
            throw new RuntimeException('This request is no longer valid.');
        }

        DB::transaction(function () use ($request, $employee, $reviewer, $user, $organization): void {
            OrganizationProvisioner::admit($organization, $user, $employee);

            $request->forceFill([
                'status' => OrganizationJoinRequest::APPROVED,
                'employee_id' => $employee->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
        });

        $user->notify(new JoinRequestDecisionNotification($organization->name, approved: true));

        ActivityLogger::log(
            event: 'updated',
            description: "Approved {$user->full_name}'s request to join and linked them to {$employee->full_name}",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );
    }

    /**
     * Turn a pending request down, optionally saying why.
     */
    public static function decline(OrganizationJoinRequest $request, User $reviewer, ?string $reason = null): void
    {
        if ($request->status !== OrganizationJoinRequest::PENDING) {
            throw new RuntimeException('This request has already been reviewed.');
        }

        $request->forceFill([
            'status' => OrganizationJoinRequest::DECLINED,
            'decline_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $user = $request->user;
        $organization = $request->organization;

        if ($user !== null && $organization !== null) {
            $user->notify(new JoinRequestDecisionNotification($organization->name, approved: false, reason: $reason));
        }

        ActivityLogger::log(
            event: 'updated',
            description: 'Declined '.($user?->full_name ?? 'a').' request to join',
            logName: 'employees',
            subjectLabel: $user?->full_name,
        );
    }

    /**
     * A user's own pending requests, across every organisation — what the mobile
     * app shows somebody who is waiting.
     *
     * @return Collection<int, OrganizationJoinRequest>
     */
    public static function pendingFor(User $user): Collection
    {
        return OrganizationJoinRequest::query()
            ->withoutGlobalScopes()
            ->pending()
            ->where('user_id', $user->id)
            ->with('organization')
            ->latest('id')
            ->get();
    }

    /**
     * The one unclaimed roster line whose email is this identity's, if there is
     * exactly one.
     *
     * Exactness matters twice over. The comparison is `lower(col) = ?` rather than
     * a LIKE so wildcards in an address cannot widen it, and an ambiguous result
     * (two roster lines sharing an address) deliberately matches *nothing* — better
     * to make HR choose than to guess which person somebody is.
     */
    private static function rosterMatch(Organization $organization, User $user): ?Employee
    {
        $email = Str::lower(trim($user->email));

        if ($email === '') {
            return null;
        }

        $candidates = Employee::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->whereNull('user_id')
            ->whereRaw('lower(email) = ?', [$email])
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * This identity's existing request row at an organisation, whatever its state.
     */
    private static function existing(Organization $organization, User $user): ?OrganizationJoinRequest
    {
        return OrganizationJoinRequest::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
