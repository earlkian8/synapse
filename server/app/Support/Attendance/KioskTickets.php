<?php

namespace App\Support\Attendance;

use App\Models\AttendanceKiosk;
use App\Models\EmployeeKioskPin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What a kiosk holds between somebody's PIN and their punch (ADR 0092): a
 * ticket, so the PIN is sent once and not kept on the device.
 *
 * A ticket is random, lives for {@see SECONDS} seconds, and is good only at the
 * kiosk that signed the person in. Only its SHA-256 is a cache key. It is spent
 * by the punch it was for, and dropped when the person walks away.
 */
final class KioskTickets
{
    /** How long somebody has between their PIN and their punch. */
    public const SECONDS = 120;

    public static function issue(AttendanceKiosk $kiosk, EmployeeKioskPin $pin): string
    {
        $ticket = Str::random(48);

        Cache::put(self::key($ticket), [
            'kiosk' => $kiosk->id,
            'pin' => $pin->id,
            'employee' => $pin->employee_id,
            'issued_at' => $pin->issued_at?->getTimestamp(),
        ], self::SECONDS);

        return $ticket;
    }

    /**
     * The PIN a ticket was issued against, with its employee — when the ticket
     * is live, was issued at this kiosk, and the PIN has not been withdrawn or
     * replaced by HR since.
     */
    public static function find(AttendanceKiosk $kiosk, ?string $ticket): ?EmployeeKioskPin
    {
        if ($ticket === null || strlen($ticket) !== 48) {
            return null;
        }

        $held = Cache::get(self::key($ticket));

        if (! is_array($held) || ($held['kiosk'] ?? null) !== $kiosk->id) {
            return null;
        }

        $pin = EmployeeKioskPin::query()->with('employee')->find($held['pin'] ?? null);

        return $pin !== null
            && $pin->employee !== null
            && $pin->employee_id === ($held['employee'] ?? null)
            && $pin->issued_at?->getTimestamp() === ($held['issued_at'] ?? null)
            ? $pin
            : null;
    }

    public static function forget(?string $ticket): void
    {
        if ($ticket !== null && $ticket !== '') {
            Cache::forget(self::key($ticket));
        }
    }

    private static function key(string $ticket): string
    {
        return 'kiosk-ticket:'.hash('sha256', $ticket);
    }
}
