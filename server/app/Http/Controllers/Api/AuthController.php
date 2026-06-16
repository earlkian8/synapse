<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token issuance for the mobile DTR app. Separate from the web's session-based
 * Fortify flow: mobile clients exchange credentials for a Sanctum personal access
 * token and send it as a Bearer token on every subsequent request.
 */
class AuthController extends Controller
{
    /**
     * Exchange credentials for a personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        // No tenant is bound yet (no token), so the organisation scope is a no-op
        // and we can resolve the account by its globally-unique email.
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account is inactive.']]);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'mobile');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * The signed-in user's profile and linked employee.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
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
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $employee = $user->employee()->with('workSchedule')->first();

        return [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'photo' => $employee->photo_url,
                'schedule' => $employee->workSchedule?->only(['name', 'start_time', 'end_time', 'grace_minutes', 'required_hours']),
            ] : null,
            'can_clock' => $user->can('attendance.clock'),
        ];
    }
}
