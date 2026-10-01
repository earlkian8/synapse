<?php

namespace App\Services\Assistant\Retrieval;

use App\Models\Applicant;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Who (or what) a turn is about, once the retriever has resolved it against the
 * workspace.
 *
 * A subject is always a real row the signed-in user is allowed to reach — it is
 * resolved by querying, never by trusting a name the model produced — so every
 * module that contributes context to a brief can take it as given and go
 * straight to its own tables.
 */
final class RetrievedSubject
{
    public const EMPLOYEE = 'employee';

    public const APPLICANT = 'applicant';

    public function __construct(
        /** @var self::EMPLOYEE|self::APPLICANT */
        public readonly string $kind,
        public readonly int $id,
        public readonly string $label,
        public readonly Model $model,
        /** Whether this is the signed-in user's own record — self-service. */
        public readonly bool $isSelf = false,
    ) {}

    public static function employee(Employee $employee, bool $isSelf = false): self
    {
        return new self(self::EMPLOYEE, $employee->id, $employee->full_name, $employee, $isSelf);
    }

    public static function applicant(Applicant $applicant): self
    {
        return new self(self::APPLICANT, $applicant->id, $applicant->full_name, $applicant);
    }

    public function isEmployee(): bool
    {
        return $this->kind === self::EMPLOYEE;
    }

    public function isApplicant(): bool
    {
        return $this->kind === self::APPLICANT;
    }

    /**
     * The subject as an {@see Employee}, or null when it is somebody who does
     * not work here (yet).
     */
    public function employeeModel(): ?Employee
    {
        return $this->model instanceof Employee ? $this->model : null;
    }
}
