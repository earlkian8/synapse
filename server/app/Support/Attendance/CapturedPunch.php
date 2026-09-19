<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;

/**
 * What {@see AttendanceClock::capture()} did with a punch: the day it landed on,
 * the punch, and whether it had already been received — a device or a phone
 * sending the same punch again gets the punch it sent the first time.
 */
final readonly class CapturedPunch
{
    public function __construct(
        public AttendanceRecord $record,
        public AttendancePunch $punch,
        public bool $duplicate = false,
    ) {}
}
