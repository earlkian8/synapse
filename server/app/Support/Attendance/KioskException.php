<?php

namespace App\Support\Attendance;

use RuntimeException;

/**
 * A kiosk refused something (ADR 0092) — a number and PIN that don't match, a
 * locked PIN, a PIN too easy to guess. The message is written for the person
 * standing at the kiosk.
 */
class KioskException extends RuntimeException {}
