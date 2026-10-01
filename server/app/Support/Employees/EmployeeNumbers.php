<?php

namespace App\Support\Employees;

use App\Models\Employee;

/**
 * The one place an employee number is generated.
 *
 * The number is derived from *this tenant's own* series rather than from the
 * `employees` primary key, which is a single sequence shared by every
 * organisation on the instance: keying off `max(id)` made a new tenant's first
 * hire EMP-00412 because somebody else's roster got there first, and let two
 * tenants land on the same number when their id ranges interleaved.
 *
 * Reads run through the organisation scope, so "the highest number so far" means
 * the highest in the caller's organisation and nowhere else. Archived rows count
 * — a number belonging to someone who left is not free to reissue.
 */
final class EmployeeNumbers
{
    private const PREFIX = 'EMP-';

    private const PAD = 5;

    /**
     * The next free number for the current tenant, e.g. `EMP-00007`.
     */
    public static function next(): string
    {
        $highest = 0;

        Employee::withTrashed()
            ->whereNotNull('employee_no')
            ->pluck('employee_no')
            ->each(function (string $number) use (&$highest): void {
                // Tolerate hand-typed numbers in any shape; only the trailing
                // digits are treated as the series.
                if (preg_match('/(\d+)\s*$/', $number, $matches) === 1) {
                    $highest = max($highest, (int) $matches[1]);
                }
            });

        return self::format($highest + 1);
    }

    /**
     * Render a sequence number in the canonical shape.
     */
    public static function format(int $sequence): string
    {
        return self::PREFIX.str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }
}
