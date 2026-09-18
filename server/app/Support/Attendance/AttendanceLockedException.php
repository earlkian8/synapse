<?php

namespace App\Support\Attendance;

/**
 * Thrown by {@see PeriodLock} when something would change a day inside a locked
 * attendance period (ADR 0039).
 *
 * It is a kind of {@see AttendancePunchException} on purpose: a punch into a
 * locked day is a refused punch, and every path that already reports a refused
 * punch — the web clock, the mobile API, the assistant — reports this one too
 * without having to know about periods.
 */
class AttendanceLockedException extends AttendancePunchException
{
    public static function forDate(string $date, string $period): self
    {
        return new self("That day is in a locked attendance period ({$period}). Ask HR to unlock the period to change it.");
    }
}
