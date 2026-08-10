<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one way to issue, redeem, or withdraw an invitation onto a roster line
 * (ADR 0026). The ERP controller and the mobile API both come through here, so the
 * rules below hold no matter which door was used.
 *
 * **The secret.** Each invitation carries one grant in two forms: a long link token
 * (emailed, stored only as its sha256) and a short retypeable code. Either redeems
 * it. Reissuing supersedes — the previous code stops working the moment a new one
 * is sent, so a forwarded old mail cannot be used behind the recipient's back.
 *
 * **Possession is the authorisation.** Redeeming deliberately does *not* require
 * the claimant's email to match the address invited: people register with the
 * address they actually use, which is frequently not the one payroll has. What the
 * secret proves is that HR handed it to them. The *listing* endpoint is stricter —
 * it only surfaces invitations addressed to the caller's own mailbox — because
 * that is discovery rather than redemption.
 *
 * **Scope escapes are confined to this class.** Every candidate-facing lookup runs
 * `withoutGlobalScopes()` on purpose: an invitation is issued inside a tenant and
 * answered from outside one, by somebody who is not yet a member of it. Nowhere
 * else in the codebase should need that.
 */
class EmployeeInvitations
{
    /**
     * How long a freshly-issued invitation stays redeemable. Evaluated on read —
     * nothing sweeps the table, so this is a property of the row, not of a job.
     */
    public const EXPIRES_AFTER_DAYS = 14;

    /**
     * Invite the identity behind an employee's email address to claim that roster
     * line, superseding any invitation already outstanding against it.
     *
     * @param  string|null  $email  Override the address on the 201 record.
     *
     * @throws RuntimeException when the line is already claimed or has no address.
     */
    public static function invite(Employee $employee, User $actor, ?string $email = null): EmployeeInvitation
    {
        if ($employee->user_id !== null) {
            throw new RuntimeException("{$employee->full_name} already has app access.");
        }

        $address = trim((string) ($email ?? $employee->email));

        if ($address === '') {
            throw new RuntimeException("{$employee->full_name} has no email address on file to invite.");
        }

        $organization = $employee->organization;

        if ($organization === null) {
            throw new RuntimeException('This employee is not attached to an organisation.');
        }

        [$invitation, $plainToken] = DB::transaction(function () use ($employee, $actor, $address): array {
            // Supersede: anything still outstanding against this line is withdrawn
            // before the replacement is minted, so exactly one code ever works.
            $employee->invitations()->outstanding()->update(['revoked_at' => now()]);

            $plainToken = Str::random(64);

            $invitation = EmployeeInvitation::create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'email' => $address,
                'token' => hash('sha256', $plainToken),
                'code' => JoinCode::uniqueFor(EmployeeInvitation::class, 'code', JoinCode::INVITATION_LENGTH),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ]);

            return [$invitation, $plainToken];
        });

        // Sent after commit so a rolled-back transaction never leaks a live code.
        $invitation->notify(new EmployeeInvitationNotification(
            employeeName: $employee->first_name ?: $employee->full_name,
            organizationName: $organization->name,
            code: $invitation->code,
            url: route('invite.show', ['token' => $plainToken]),
            expiresAt: $invitation->expires_at,
        ));

        ActivityLogger::log(
            event: 'created',
            description: "Invited {$employee->full_name} to the SYNAPSE app",
            subject: $employee,
            properties: ['email' => $address],
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        return $invitation;
    }

    /**
     * Withdraw the outstanding invitation on a roster line. Returns whether there
     * was one to withdraw.
     */
    public static function revoke(Employee $employee): bool
    {
        $revoked = $employee->invitations()->outstanding()->update(['revoked_at' => now()]) > 0;

        if ($revoked) {
            ActivityLogger::log(
                event: 'updated',
                description: "Revoked the app invitation for {$employee->full_name}",
                subject: $employee,
                logName: 'employees',
                subjectLabel: $employee->full_name,
            );
        }

        return $revoked;
    }

    /**
     * Resolve a redeemable invitation from a typed code, or null.
     */
    public static function findByCode(?string $code): ?EmployeeInvitation
    {
        $normalized = JoinCode::normalize($code);

        if ($normalized === '') {
            return null;
        }

        return self::redeemable(fn ($query) => $query->where('code', $normalized));
    }

    /**
     * Resolve a redeemable invitation from a link token, or null.
     */
    public static function findByToken(?string $token): ?EmployeeInvitation
    {
        $token = trim((string) $token);

        if ($token === '') {
            return null;
        }

        return self::redeemable(fn ($query) => $query->where('token', hash('sha256', $token)));
    }

    /**
     * Outstanding invitations addressed to this identity's own mailbox.
     *
     * Unlike redemption, discovery *is* matched on email: this answers "what is
     * waiting for me?" for somebody who has proved nothing but their login.
     *
     * @return Collection<int, EmployeeInvitation>
     */
    public static function for(User $user): Collection
    {
        return EmployeeInvitation::query()
            ->withoutGlobalScopes()
            ->outstanding()
            ->whereRaw('lower(email) = ?', [Str::lower($user->email)])
            ->with(['organization', 'employee.position', 'employee.department'])
            ->latest('id')
            ->get();
    }

    /**
     * Redeem an invitation: bind this identity to the roster line it names and
     * admit them to the organisation.
     *
     * @throws RuntimeException when it has lapsed, or the line was claimed meanwhile.
     */
    public static function accept(EmployeeInvitation $invitation, User $user): Organization
    {
        return DB::transaction(function () use ($invitation, $user): Organization {
            // Re-read under the row lock: two people racing the same code, or a
            // revoke landing mid-flight, must not both get through.
            $fresh = EmployeeInvitation::query()
                ->withoutGlobalScopes()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || ! $fresh->isRedeemable()) {
                throw new RuntimeException('This invitation is no longer valid. Ask your HR team for a new one.');
            }

            $organization = Organization::query()->withoutGlobalScopes()->find($fresh->organization_id);
            $employee = Employee::query()->withoutGlobalScopes()->find($fresh->employee_id);

            if ($organization === null || $employee === null) {
                throw new RuntimeException('This invitation is no longer valid. Ask your HR team for a new one.');
            }

            if ($employee->user_id !== null && $employee->user_id !== $user->id) {
                throw new RuntimeException('Somebody has already claimed this record. Ask your HR team for a new invitation.');
            }

            // One identity may only hold one employee record per company.
            $clash = Employee::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->whereKeyNot($employee->getKey())
                ->exists();

            if ($clash) {
                throw new RuntimeException("You already have a record at {$organization->name}.");
            }

            OrganizationProvisioner::admit($organization, $user, $employee);

            $fresh->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            // Acceptance is answered from outside the tenant that issued it — the
            // caller may be bound to another company, or to none — so bind the
            // owning organisation while the entry is written, or it would land in
            // the wrong workforce's audit trail.
            app(Tenancy::class)->runFor($organization, fn () => ActivityLogger::log(
                event: 'updated',
                description: "{$user->full_name} accepted their invitation and now has app access",
                subject: $employee,
                logName: 'employees',
                subjectLabel: $employee->full_name,
            ));

            return $organization;
        });
    }

    /**
     * Turn an invitation down. Idempotent from the caller's point of view — an
     * invitation that is no longer redeemable is simply left alone.
     */
    public static function decline(EmployeeInvitation $invitation): void
    {
        if ($invitation->isRedeemable()) {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Look up a redeemable invitation past every tenant boundary (see the class
     * docblock) with the given secret applied.
     *
     * @param  callable(Builder<EmployeeInvitation>): mixed  $secret
     */
    private static function redeemable(callable $secret): ?EmployeeInvitation
    {
        $query = EmployeeInvitation::query()
            ->withoutGlobalScopes()
            ->outstanding()
            ->with(['organization', 'employee.position', 'employee.department']);

        $secret($query);

        return $query->first();
    }
}
