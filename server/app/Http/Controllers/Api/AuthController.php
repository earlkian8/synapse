<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token issuance for the mobile app. Separate from the web's session-based Fortify
 * flow: mobile clients exchange credentials for a Sanctum personal access token and
 * send it as a Bearer token on every request.
 *
 * A user is a global identity that may belong to several organisations (ADR 0023).
 * Each token is minted **bound to one active organisation**
 * (`personal_access_tokens.organization_id`); switching companies issues a fresh
 * token for the chosen organisation. Every payload carries the caller's full list
 * of organisations so the app can offer the switcher.
 */
class AuthController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Exchange credentials for a token bound to the identity's default organisation.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        // No tenant is bound yet; the email is a global identity key.
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account is inactive.']]);
        }

        $active = $user->defaultOrganization();

        if ($active === null) {
            throw ValidationException::withMessages(['email' => ['This account is not linked to any organisation.']]);
        }

        return response()->json([
            'token' => $this->issueToken($user, $active, $credentials['device_name'] ?? null),
            'user' => $this->userPayload($user, $active),
        ]);
    }

    /**
     * Switch the active organisation: validate membership and issue a fresh token
     * bound to the chosen company, revoking the one used for this request.
     */
    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $organization = $user->memberships()->where('organizations.id', $data['organization_id'])->first();

        if ($organization === null) {
            throw ValidationException::withMessages(['organization_id' => ['You are not a member of that organisation.']]);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'token' => $this->issueToken($user, $organization, 'mobile'),
            'user' => $this->userPayload($user, $organization),
        ]);
    }

    /**
     * The signed-in user, their active organisation and linked employee.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $active = $this->tenancy->organization() ?? $user->defaultOrganization();

        if ($active === null) {
            abort(403, 'This account is not linked to any organisation.');
        }

        return response()->json(['user' => $this->userPayload($user, $active)]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Mint a personal access token bound to the given organisation.
     */
    private function issueToken(User $user, Organization $organization, ?string $deviceName): string
    {
        $token = $user->createToken($deviceName ?? 'mobile');
        $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

        return $token->plainTextToken;
    }

    /**
     * Build the payload for an identity viewed from a specific active organisation.
     * Computed with the organisation bound as the tenant so the employee record,
     * roles and permissions resolve to that organisation.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user, Organization $active): array
    {
        return $this->tenancy->runFor($active, function () use ($user, $active): array {
            // Drop any roles/permissions memoised under a previous tenant.
            $user->forgetCachedPermissions();

            $employee = $user->employee()->with('workSchedule')->first();

            return [
                'id' => $user->id,
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'organization' => $this->organizationPayload($active),
                'organizations' => $user->memberships()
                    ->orderByDesc('organization_user.is_default')
                    ->orderBy('organizations.name')
                    ->get()
                    ->map(fn (Organization $organization): array => $this->organizationPayload($organization))
                    ->all(),
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'employee_no' => $employee->employee_no,
                    'photo' => $employee->photo_url,
                    'schedule' => $employee->workSchedule?->only(['name', 'start_time', 'end_time', 'grace_minutes', 'required_hours']),
                ] : null,
                'can_clock' => $user->can('attendance.clock'),
            ];
        });
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
