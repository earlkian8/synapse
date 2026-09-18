<?php

namespace App\Support\Attendance;

use RuntimeException;

/**
 * An attendance action refused for a reason a person can act on — the message
 * is written to be shown as it is. Controllers map it to a warning toast (web)
 * or a 422 (API), and the assistant to a failed tool result.
 *
 * {@see AttendancePunchException} (a punch the day's state does not allow),
 * {@see AttendanceLockedException} (a day in a locked period) and
 * {@see AttendanceRequestException} (a request that cannot be filed or decided)
 * are its kinds.
 */
class AttendanceException extends RuntimeException {}
