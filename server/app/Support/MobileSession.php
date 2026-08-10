<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Builds every session the mobile app is ever handed: the token, and the payload
 * describing who the holder is and where they are standing.
 *
 * There are four ways a session comes into being — signing in, registering,
 * switching company, and joining one — and before ADR 0026 the first was the only
 * one, so its shape was inlined in the controller. Now that they must all agree
 * (down to which organisation the token is bound to), they are all minted here.
 *
 * **"Nowhere" is a legitimate place to stand.** An identity that has registered but
 * not yet joined a company gets a real token with `organization_id` left null and
 * an `organization: null` payload. The app routes that state to the join screen
 * rather than treating it as a failed login, which is the whole point of letting
 * people register before anybody has heard of them.
 */
class MobileSession
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * A fresh token bound to `$organization` (or to nothing), plus the payload
     * describing the session it opens.
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public function open(User $user, ?Organization $organization, ?string $deviceName = null): array
    {
        return [
            'token' => $this->issueToken($user, $organization, $deviceName),
            'user' => $this->payload($user, $organization),
        ];
    }

    /**
     * Re-issue the session on the current token, revoking the one that carried the
     * request. Used when the holder moves — switching company, or joining their
     * first one — so the token's bound organisation never lags the payload's.
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public function reissue(User $user, ?Organization $organization, ?string $deviceName = 'mobile'): array
    {
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return $this->open($user, $organization, $deviceName);
    }

    /**
     * Describe an identity as seen from a given organisation.
     *
     * Computed with that organisation bound as the tenant, so the employee record,
     * roles and permissions all resolve to it rather than to whichever company the
     * request happened to arrive under.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user, ?Organization $organization): array
    {
        if ($organization === null) {
            return $this->describe($user, null);
        }

        return $this->tenancy->runFor($organization, fn (): array => $this->describe($user, $organization));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(User $user, ?Organization $organization): array
    {
        // Drop any roles/permissions memoised under a previous tenant.
        $user->forgetCachedPermissions();

        $employee = $organization === null
            ? null
            : $user->employee()->with('workSchedule')->first();

        $memberships = $user->memberships()
            ->orderByDesc('organization_user.is_default')
            ->orderBy('organizations.name')
            ->get();

        return [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'organization' => $organization === null ? null : $this->organizationPayload($organization),
            'organizations' => $memberships
                ->map(fn (Organization $membership): array => $this->organizationPayload($membership))
                ->all(),
            // The single flag the client routes on: no company means the join
            // screen, not an error.
            'needs_workspace' => $memberships->isEmpty(),
            'pending_requests' => WorkspaceJoin::pendingFor($user)
                ->map(fn ($request): array => [
                    'id' => $request->id,
                    'organization' => $request->organization?->name,
                    'requested_human' => $request->created_at?->diffForHumans(),
                ])
                ->all(),
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'photo' => $employee->photo_url,
                'schedule' => $employee->workSchedule?->only(['name', 'start_time', 'end_time', 'grace_minutes', 'required_hours']),
            ] : null,
            'can_clock' => $organization !== null && $user->can('attendance.clock'),
        ];
    }

    /**
     * Mint a personal access token, bound to one organisation or to none.
     */
    private function issueToken(User $user, ?Organization $organization, ?string $deviceName): string
    {
        $token = $user->createToken($deviceName ?: 'mobile');

        $token->accessToken->forceFill(['organization_id' => $organization?->id])->save();

        return $token->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationPayload(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'logo' => $organization->logo_url,
            'initials' => $organization->initials(),
        ];
    }
}
