<?php

namespace App\Support\Users;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What a person changes on their own sign-in account: their name and email
 * address, and their password (ADR 0082).
 *
 * The web's Settings, the mobile app's Account screens and a forgotten-password
 * reset all come through here, so the three cannot drift apart. It never
 * touches the employee record: that is the 201 file HR keeps in each workspace,
 * and the account is the holder's in all of them (ADR 0023, ADR 0057).
 *
 * Two rules hold whichever way in:
 *
 * - **A new email address is confirmed again.** It is marked unconfirmed and a
 *   fresh code is mailed to it, as when HR changes it ({@see UserAccounts}).
 * - **A new password ends the app's other sessions.** App tokens do not expire,
 *   so whoever held the old password on another phone would otherwise stay
 *   signed in for good. The phone that made the change keeps its own session.
 */
class OwnAccount
{
    /** The account fields a person edits themselves. */
    public const DETAILS = ['first_name', 'middle_name', 'last_name', 'email'];

    /**
     * Save the person's name and email address.
     *
     * @param  array<string, mixed>  $details
     * @return array{email_changed: bool, verification_sent: bool}
     */
    public function update(User $user, array $details): array
    {
        $user->fill(array_intersect_key($details, array_flip(self::DETAILS)));

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return [
            'email_changed' => $emailChanged,
            'verification_sent' => $emailChanged && $this->sendVerification($user),
        ];
    }

    /**
     * Set a new password and sign the app out everywhere but `$keep`, the
     * session that made the change (none, from the web or a reset link).
     */
    public function changePassword(User $user, string $password, ?PersonalAccessToken $keep = null): void
    {
        $user->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
        ])->save();

        $user->tokens()
            ->when($keep !== null, fn ($query) => $query->whereKeyNot($keep->getKey()))
            ->delete();
    }

    /**
     * Email the confirmation code for a new address. A transport failure is
     * reported, never thrown, so a mail outage never undoes the change: the
     * confirmation screen can send another.
     */
    private function sendVerification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
