<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;

/**
 * Starts an employee's offboarding: opens an {@see OffboardingCase} and seeds it
 * with the standard clearance checklist, routing each sign-off to the department
 * that owns it.
 *
 * This is the counterpart of {@see OnboardingProvisioner} at the other end of the
 * employment life cycle — the single place the exit checklist is defined, so the
 * controller and any future automation produce identical cases (ADR 0016). It is
 * idempotent per employee: an employee already exiting is returned as-is.
 */
class OffboardingProvisioner
{
    /**
     * The baseline clearance every exit starts with. `department` is a Company-Setup
     * department **code** (resolved to that department, if it exists), or the
     * sentinel `__own__` for the employee's own department.
     *
     * @var list<array{item: string, department: string}>
     */
    private const STANDARD_ITEMS = [
        ['item' => 'Knowledge transfer & turnover of responsibilities', 'department' => '__own__'],
        ['item' => 'Return department files, documents & tools', 'department' => '__own__'],
        ['item' => 'Return laptop, peripherals & assigned devices', 'department' => 'IT'],
        ['item' => 'Revoke email, system & application accounts', 'department' => 'IT'],
        ['item' => 'Settle cash advances, loans & liquidations', 'department' => 'FIN'],
        ['item' => 'Process final pay & last salary release', 'department' => 'FIN'],
        ['item' => 'Return company ID, access card & keys', 'department' => 'HR'],
        ['item' => 'Conduct exit interview', 'department' => 'HR'],
        ['item' => 'Settle remaining leave balance & benefits', 'department' => 'HR'],
        ['item' => 'Issue Certificate of Employment & clearance', 'department' => 'HR'],
    ];

    /**
     * Begin offboarding for an employee, seeding the standard clearance checklist.
     * Returns the existing case if the employee already has one.
     *
     * @param  array{type?: string, notice_date?: ?string, last_working_day?: ?string, reason?: ?string}  $attributes
     */
    public static function start(Employee $employee, array $attributes = []): OffboardingCase
    {
        if ($existing = $employee->offboardingCase()->first()) {
            return $existing;
        }

        $case = OffboardingCase::create([
            'employee_id' => $employee->id,
            'type' => $attributes['type'] ?? 'resignation',
            'notice_date' => $attributes['notice_date'] ?? null,
            'last_working_day' => $attributes['last_working_day'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'status' => 'initiated',
        ]);

        self::seedClearance($case, $employee);

        return $case;
    }

    /**
     * Instantiate the standard checklist on a case, resolving each item's owning
     * department from its code (or the employee's own department).
     */
    private static function seedClearance(OffboardingCase $case, Employee $employee): void
    {
        $byCode = Department::query()
            ->get(['id', 'code'])
            ->keyBy(fn (Department $department): string => strtoupper((string) $department->code));

        foreach (self::STANDARD_ITEMS as $index => $item) {
            $departmentId = $item['department'] === '__own__'
                ? $employee->department_id
                : $byCode->get(strtoupper($item['department']))?->id;

            $case->clearanceItems()->create([
                'item' => $item['item'],
                'department_id' => $departmentId,
                'status' => 'pending',
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Derive a case's clearance status from its item counts — `pending` while
     * untouched, `cleared` once every item is signed off, `in_progress` in
     * between. Never stored, so it cannot drift (mirrors the onboarding-progress
     * norm). The single source of truth for the three ERD §9 clearance states.
     */
    public static function clearanceStatus(int $total, int $cleared): string
    {
        return match (true) {
            $total === 0, $cleared === 0 => 'pending',
            $cleared >= $total => 'cleared',
            default => 'in_progress',
        };
    }
}
