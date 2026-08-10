<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Services\Assistant\Modules\EmployeeModule;
use Illuminate\Support\Carbon;

/**
 * What the assistant is allowed to know about an employee.
 *
 * Everything the agentic assistant reads out of the employee directory passes
 * through here first, because a tool result does not stay on this server: it is
 * fed back to Gemini as context, echoed into the chat transcript, and rendered
 * on a screen somebody may be sharing. So the policy is a deny-list applied at
 * the projection, not a hope that the model behaves.
 *
 * Two rules:
 *
 * 1. {@see WITHHELD} never leaves the building through the assistant — for
 *    anybody, at any permission level. Statutory numbers and bank details exist
 *    for filing, pay exists for the ML models, and home address and date of
 *    birth are personal-safety data; none of them have an operational reason to
 *    be discussed in a chat window. They can still be *written* through the
 *    assistant (the user is supplying their own data on purpose) and they are
 *    still readable in the 201 file, which is access-controlled and audited.
 * 2. Free text that a person can influence — names, titles, department labels —
 *    is normalised before it reaches the model, so a record cannot smuggle a
 *    newline and a fresh set of "instructions" into the prompt.
 *
 * @see EmployeeModule
 */
final class EmployeeDisclosure
{
    /**
     * Columns the assistant will never disclose. Guarded by a test that fails
     * if a projection here starts emitting one of them.
     *
     * @var list<string>
     */
    public const WITHHELD = [
        'tin',
        'sss_no',
        'philhealth_no',
        'pagibig_no',
        'bank_name',
        'bank_account_no',
        'basic_salary',
        'address',
        'birth_date',
    ];

    /** Longest a retrieved free-text value may be before it is truncated. */
    private const MAX_TEXT = 120;

    /** Hard ceiling on how many people one tool call may return. */
    public const MAX_ROWS = 25;

    /**
     * The one-line form used in lists: who they are and where they sit. No
     * contact details — a list is the shape an exfiltration attempt takes, so
     * bulk reads stay coarser than a deliberate single-record read.
     *
     * @return array<string, string|null>
     */
    public static function summary(Employee $employee): array
    {
        return [
            'name' => self::text($employee->full_name),
            'employee_no' => self::text($employee->employee_no),
            'position' => self::text($employee->position?->title),
            'department' => self::text($employee->department?->name),
            'employment_type' => self::text($employee->employment_type),
            'status' => $employee->trashed() ? 'archived' : self::text($employee->employment_status),
        ];
    }

    /**
     * The single-record form: the summary plus the operational detail somebody
     * actually asks an HR assistant for — reporting line, schedule, key dates,
     * work contact. Still nothing from {@see WITHHELD}.
     *
     * @return array<string, string|null>
     */
    public static function profile(Employee $employee): array
    {
        return self::summary($employee) + [
            'manager' => self::text($employee->manager?->full_name),
            'work_schedule' => self::text($employee->workSchedule?->name),
            'date_hired' => $employee->date_hired?->toDateString(),
            'date_regularized' => $employee->date_regularized?->toDateString(),
            'tenure' => self::tenure($employee),
            'email' => self::text($employee->email),
            'phone' => self::text($employee->phone),
        ];
    }

    /**
     * How long they have been here, in words. Derived rather than disclosing a
     * raw date somebody could correlate.
     */
    public static function tenure(Employee $employee): ?string
    {
        if ($employee->date_hired === null) {
            return null;
        }

        $months = (int) $employee->date_hired->diffInMonths(Carbon::today());

        if ($months < 1) {
            return 'under a month';
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;

        $parts = [];

        if ($years > 0) {
            $parts[] = $years.' year'.($years === 1 ? '' : 's');
        }

        if ($rest > 0) {
            $parts[] = $rest.' month'.($rest === 1 ? '' : 's');
        }

        return implode(' ', $parts);
    }

    /**
     * Normalise a retrieved string before it is handed to the model.
     *
     * Control characters and line breaks are what turn a stored value into a
     * second set of instructions once it is interpolated into a prompt, so they
     * are collapsed to spaces and the result is capped. This is a hardening
     * measure, not the guarantee itself: the real guarantee is that the model
     * can only ever *ask* for an action, and every action re-checks the
     * signed-in user's permission before it runs.
     */
    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip C0/C1 control characters (newlines included), then collapse runs
        // of whitespace so nothing can lay out a fake prompt turn.
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        if ($clean === '') {
            return null;
        }

        return mb_strimwidth($clean, 0, self::MAX_TEXT, '…');
    }

    /**
     * Flatten a projection into the `meta` chips a result card renders, dropping
     * empties so a sparse record does not render a row of dashes.
     *
     * @param  array<string, string|null>  $projection
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function meta(array $projection, array $keys): array
    {
        $meta = [];

        foreach ($keys as $key) {
            if (filled($projection[$key] ?? null)) {
                $meta[] = (string) $projection[$key];
            }
        }

        return $meta;
    }
}
