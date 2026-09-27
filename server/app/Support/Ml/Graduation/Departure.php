<?php

namespace App\Support\Ml\Graduation;

use App\Models\Employee;
use App\Models\OffboardingCase;
use Carbon\CarbonImmutable;

/**
 * Whether, when and how an employee left, as the graduation training sets need it.
 * A departure is dated and typed only through a completed offboarding case; an
 * employee marked resigned or terminated without one left at an unknown time for an
 * unknown reason, and the training sets treat that as exactly that.
 *
 * Reads the employee's loaded `offboardingCase`.
 */
final class Departure
{
    private function __construct(
        public readonly bool $left,
        public readonly ?CarbonImmutable $on,
        public readonly ?string $type,
    ) {}

    public static function of(Employee $employee): self
    {
        if (! in_array($employee->employment_status, FieldCounts::DEPARTED, true)) {
            return new self(false, null, null);
        }

        /** @var OffboardingCase|null $case */
        $case = $employee->relationLoaded('offboardingCase') ? $employee->offboardingCase : null;

        if ($case === null || $case->status !== 'completed') {
            return new self(true, null, null);
        }

        $on = $case->last_working_day ?? $case->completed_at;

        return new self(true, $on ? CarbonImmutable::instance($on)->startOfDay() : null, $case->type);
    }

    /** Left, but with no date or type on record. */
    public function unrecorded(): bool
    {
        return $this->left && ($this->on === null || $this->type === null);
    }
}
