<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAccountRequest;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use App\Support\Users\OwnAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The person's own sign-in account, from the app's Profile (ADR 0082): their
 * name and email address, and their password. The employee record beside it is
 * HR's and is read-only here — see {@see ProfileController}.
 *
 * Works whether or not the session is bound to a company: the account is the
 * person's in every workspace. Thin controller; {@see OwnAccount} does the work.
 */
class AccountController extends Controller
{
    public function __construct(private readonly OwnAccount $account) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    /**
     * Save the name and email address. A new address needs the current password
     * ({@see UpdateAccountRequest}) and is confirmed again with a mailed code.
     */
    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $outcome = $this->account->update($user, $request->safe()->only(OwnAccount::DETAILS));

        return response()->json([
            'data' => $this->present($user->refresh()),
            'email_changed' => $outcome['email_changed'],
            'message' => match (true) {
                $outcome['verification_sent'] => "Saved. We sent a code to {$user->email} to confirm it.",
                $outcome['email_changed'] => 'Saved. Confirm the new address the next time you sign in on the web.',
                default => 'Saved.',
            },
        ]);
    }

    /**
     * Change the password — the web's rules — and sign the app out on every
     * other phone. This one stays signed in.
     */
    public function password(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $this->account->changePassword(
            $user,
            $request->string('password')->toString(),
            $current instanceof PersonalAccessToken ? $current : null,
        );

        return response()->json([
            'data' => $this->present($user->refresh()),
            'message' => 'Password changed. The app is signed out on your other devices.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
        ];
    }
}
