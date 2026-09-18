<?php

namespace App\Support\Attendance;

/**
 * Thrown when an attendance request cannot be filed, decided or cancelled —
 * already decided, the reviewer's own, or a range touching a locked period
 * (ADR 0039).
 */
class AttendanceRequestException extends AttendanceException {}
