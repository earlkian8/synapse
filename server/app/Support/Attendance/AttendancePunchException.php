<?php

namespace App\Support\Attendance;

use RuntimeException;

/**
 * Thrown by {@see AttendanceClock} when a punch is not valid for the current
 * state of the day (e.g. clocking out without first clocking in). Controllers map
 * it to a friendly toast (web) or a 422 (API).
 */
class AttendancePunchException extends RuntimeException {}
