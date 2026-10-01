<?php

namespace App\Http\Controllers\Api;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MobileSession;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Identity for the mobile app: registering one, exchanging it for a token, and
 * moving it between companies.
 *
 * Since ADR 0026 people create their own accounts here rather than receiving one
 * from an employer, so this controller answers a question it used to refuse:
 * **an identity that belongs to no organisation is valid.** Registering, and
 * signing in afterwards, both succeed with `organization: null`; the app routes
 * that to the join screen. Nothing about employment is decided here — see
 * {@see WorkspaceController} and {@see InvitationController} for the two ways in.
 *
 * Every payload and token is built by {@see MobileSession} so the four ways a
 * session is created cannot drift apart.
 */
class AuthController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly MobileSession $session,
    ) {}

    /**
     * Register a brand-new identity. Creates no employment and joins no company —
     * it hands back a signed-in session with nowhere to stand yet.
     */
    public function register(Request $request): JsonResponse
    {
        // The same name/email rules the web registration uses — the email is
        // globally unique because it *is* the identity key across every tenant.
        $validated = $request->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        return response()->json(
            $this->session->open($user, null, $validated['device_name'] ?? null),
            201,
        );
    }

    /**
     * Exchange credentials for a token bound to the identity's default company —
     * or to none, if they haven't joined one yet.
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

        return response()->json(
            $this->session->open($user, $user->defaultOrganization(), $credentials['device_name'] ?? null),
        );
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

        return response()->json($this->session->reissue($user, $organization));
    }

    /**
     * The signed-in user, their active organisation (if any) and linked employee.
     *
     * Reports strictly what *this token* can do. A token minted before the holder
     * joined anywhere stays unbound even once they have memberships, so answering
     * with their default company here would describe a workspace the token cannot
     * actually act in. The payload still lists every membership, and the client
     * binds one by calling `/auth/switch`.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->session->payload($request->user(), $this->tenancy->organization()),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
