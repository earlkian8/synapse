<?php

namespace App\Support\Attendance;

use App\Models\AttendanceKiosk;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The PINs people clock in with at a kiosk (ADR 0092), in one place: issuing
 * one, signing in with one, choosing one's own.
 *
 * A kiosk is a shared screen anybody can walk up to, so it must not say whose a
 * number is. Somebody is named only once their number **and** PIN match; every
 * other answer is the same sentence. Guessing is slowed twice over:
 *
 *  - **per person** — {@see EmployeeKioskPin::MAX_ATTEMPTS} wrong tries in a
 *    row lock the PIN for {@see EmployeeKioskPin::LOCK_MINUTES} minutes, even
 *    against the right PIN, and the lock is logged;
 *  - **per kiosk** — {@see KIOSK_FAILURES} wrong tries in
 *    {@see KIOSK_WINDOW_SECONDS} pause the kiosk, so one person cannot walk the
 *    numbers one PIN at a time.
 */
final class KioskPins
{
    /** Wrong tries a kiosk takes before it pauses. */
    public const KIOSK_FAILURES = 20;

    /** The window those wrong tries are counted over. */
    public const KIOSK_WINDOW_SECONDS = 600;

    /** Employment statuses whose holder no longer works here. */
    private const LEFT = ['resigned', 'terminated'];

    private const NO_MATCH = 'That number and PIN don’t match. Check both and try again.';

    /**
     * Issue somebody a new PIN — or a fresh one, which unlocks a locked PIN —
     * and return it: the only time it exists in the clear. It works once, to
     * choose their own at a kiosk. The caller logs it.
     */
    public static function issue(Employee $employee, User $by): string
    {
        do {
            $pin = str_pad((string) random_int(0, 10 ** EmployeeKioskPin::LENGTH - 1), EmployeeKioskPin::LENGTH, '0', STR_PAD_LEFT);
        } while (self::weakness($pin) !== null);

        $record = EmployeeKioskPin::query()->firstOrNew(['employee_id' => $employee->id]);

        $record->forceFill([
            'pin_hash' => Hash::make($pin),
            'must_change' => true,
            'failed_attempts' => 0,
            'locked_until' => null,
            'issued_by' => $by->id,
            'issued_at' => now(),
            'changed_at' => null,
        ])->save();

        return $pin;
    }

    /**
     * Who a number and PIN belong to, at a kiosk. The number is the employee
     * number as printed, or just its digits ("42" for EMP-00042), since a
     * kiosk's keypad has nothing else.
     *
     * @throws KioskException when they don't match, the PIN is locked, or the kiosk is paused
     */
    public static function signIn(AttendanceKiosk $kiosk, string $number, string $pin): EmployeeKioskPin
    {
        $key = 'kiosk-sign-in:'.$kiosk->id;

        if (RateLimiter::tooManyAttempts($key, self::KIOSK_FAILURES)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new KioskException("Too many wrong tries at this kiosk. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.');
        }

        $holders = self::holdersOf($number);

        // Hash as much for a number nobody holds as for one somebody does, so
        // the time an answer takes doesn't say which it was.
        if ($holders->isEmpty()) {
            Hash::make($pin);
            RateLimiter::hit($key, self::KIOSK_WINDOW_SECONDS);

            throw new KioskException(self::NO_MATCH);
        }

        $matched = $holders->first(fn (EmployeeKioskPin $holder): bool => Hash::check($pin, $holder->pin_hash));

        if ($matched !== null && ! $matched->isLocked()) {
            $matched->forceFill(['failed_attempts' => 0, 'last_used_at' => now()])->save();

            return $matched;
        }

        RateLimiter::hit($key, self::KIOSK_WINDOW_SECONDS);

        $locked = $holders->first(fn (EmployeeKioskPin $holder): bool => $holder->isLocked());

        if ($locked !== null) {
            throw new KioskException(self::lockedMessage($locked));
        }

        foreach ($holders as $holder) {
            self::countWrongTry($holder, $kiosk);
        }

        $now = $holders->first(fn (EmployeeKioskPin $holder): bool => $holder->isLocked());

        throw new KioskException($now !== null ? self::lockedMessage($now) : self::NO_MATCH);
    }

    /**
     * Replace a PIN with one its holder chose. The caller logs it.
     *
     * @throws KioskException when the PIN is unsuitable
     */
    public static function change(EmployeeKioskPin $record, string $pin): void
    {
        if (preg_match('/^\d{'.EmployeeKioskPin::LENGTH.'}$/', $pin) !== 1) {
            throw new KioskException('A PIN is '.EmployeeKioskPin::LENGTH.' digits.');
        }

        $weakness = self::weakness($pin);

        if ($weakness !== null) {
            throw new KioskException($weakness);
        }

        if (Hash::check($pin, $record->pin_hash)) {
            throw new KioskException('Choose a PIN of your own, not the one HR gave you.');
        }

        $record->forceFill([
            'pin_hash' => Hash::make($pin),
            'must_change' => false,
            'changed_at' => now(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    /**
     * Why a PIN is too easy to guess, or null when it is fine: one digit over
     * and over, or a straight run up or down.
     */
    public static function weakness(string $pin): ?string
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return 'That PIN is one digit over and over — too easy to guess. Choose another.';
        }

        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn (int $i): int => $digits[$i + 1] - $digits[$i], range(0, count($digits) - 2)));

        if (count($steps) === 1 && in_array(reset($steps), [1, -1], true)) {
            return 'That PIN counts straight up or down — too easy to guess. Choose another.';
        }

        return null;
    }

    /**
     * The PINs a typed number could belong to: the employee whose number it is
     * exactly, or — for bare digits — whose number ends in that sequence
     * (`42` is EMP-00042, not EMP-00142). Hand-typed numbers in other shapes
     * can share digits, so this can be more than one; the PIN tells them apart.
     * Somebody who has left, or whose record is archived, is nobody here.
     *
     * @return Collection<int, EmployeeKioskPin>
     */
    public static function holdersOf(string $number): Collection
    {
        $typed = mb_substr(trim($number), 0, 40);

        if ($typed === '') {
            return new Collection;
        }

        $digits = ctype_digit($typed) ? ltrim($typed, '0') : null;

        if ($digits === '') {
            return new Collection;
        }

        $holders = EmployeeKioskPin::query()
            ->whereHas('employee', function (Builder $query) use ($typed, $digits): void {
                $query->where(fn (Builder $query) => $query->whereNull('employment_status')->orWhereNotIn('employment_status', self::LEFT))
                    ->where(function (Builder $query) use ($typed, $digits): void {
                        $query->whereRaw('lower(employee_no) = ?', [mb_strtolower($typed)]);

                        if ($digits !== null) {
                            $query->orWhere('employee_no', 'like', '%'.$digits);
                        }
                    });
            })
            ->with('employee')
            ->get();

        $exact = $holders->filter(fn (EmployeeKioskPin $holder): bool => mb_strtolower(trim((string) $holder->employee->employee_no)) === mb_strtolower($typed));

        if ($exact->isNotEmpty() || $digits === null) {
            return $exact->values();
        }

        return $holders->filter(function (EmployeeKioskPin $holder) use ($digits): bool {
            return preg_match('/(\d+)\s*$/', (string) $holder->employee->employee_no, $match) === 1
                && ltrim($match[1], '0') === $digits;
        })->values();
    }

    /**
     * One more wrong try against a PIN. The last one allowed locks it, and the
     * lock is logged: somebody may be guessing at a colleague's PIN.
     */
    private static function countWrongTry(EmployeeKioskPin $holder, AttendanceKiosk $kiosk): void
    {
        DB::transaction(function () use ($holder, $kiosk): void {
            $holder->refresh();
            $tries = $holder->failed_attempts + 1;

            if ($tries < EmployeeKioskPin::MAX_ATTEMPTS) {
                $holder->forceFill(['failed_attempts' => $tries])->save();

                return;
            }

            $holder->forceFill([
                'failed_attempts' => 0,
                'locked_until' => now()->addMinutes(EmployeeKioskPin::LOCK_MINUTES),
            ])->save();

            ActivityLogger::log(
                event: 'updated',
                description: 'Kiosk PIN locked after '.EmployeeKioskPin::MAX_ATTEMPTS.' wrong tries at “'.$kiosk->name.'”',
                subject: $holder->employee,
                properties: ['kiosk' => $kiosk->name, 'locked_until' => $holder->locked_until?->toIso8601String()],
                logName: 'attendance',
                subjectLabel: $holder->employee?->full_name,
            );
        });
    }

    private static function lockedMessage(EmployeeKioskPin $holder): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($holder->locked_until, true) / 60));

        return "This PIN is locked after too many wrong tries. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').', or ask HR for a new one.';
    }
}
