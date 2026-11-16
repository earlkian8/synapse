<?php

namespace App\Support\Attendance;

use App\Models\AttendanceQrScan;
use RuntimeException;

/**
 * A QR scan turned away (ADR 0103). `reason` is one of
 * {@see AttendanceQrScan::REASONS}; the message is for the person
 * holding the phone and says what to do.
 */
class QrScanException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $distanceMeters = null,
    ) {
        parent::__construct($message);
    }
}
