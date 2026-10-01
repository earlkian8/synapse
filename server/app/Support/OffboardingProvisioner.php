<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;

/**
 * Starts an employee's offboarding: opens an {@see OffboardingCase} and seeds it
 * with a clearance checklist, routing each sign-off to the department that owns
 * it. The checklist comes from the best-matching (or explicitly chosen) active
 * {@see OffboardingProgram}; when the tenant has none, the built-in standard
 * checklist below is used, so an unconfigured org still gets a sensible exit.
 *
 * This is the counterpart of {@see OnboardingProvisioner} at the other end of the
 * employment life cycle — the single place the exit checklist is defined, so the
 * controller and any future automation produce identical cases (ADR 0016). It is
 * idempotent per employee: an employee already exiting is returned as-is.
 */
class OffboardingProvisioner
{
    /**
     * The baseline clearance used when no program matches. `department` is a
     * Company-Setup department **code** (resolved to that department, if it
     * exists), or the sentinel `__own__` for the employee's own department.
     *
     * @var list<array{item: string, department: string}>
     */
    public const STANDARD_ITEMS = [
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
     * Begin offboarding for an employee, seeding the clearance checklist from the
     * given program, the best-matching active one, or the built-in standard list.
     * Returns the existing case if the employee already has one.
     *
     * @param  array{type?: string, notice_date?: ?string, last_working_day?: ?string, reason?: ?string}  $attributes
     */
    public static function start(Employee $employee, array $attributes = [], ?OffboardingProgram $program = null): OffboardingCase
    {
        if ($existing = $employee->offboardingCase()->first()) {
            return $existing;
        }

        $type = $attributes['type'] ?? 'resignation';
        $program ??= self::programFor($employee, $type);

        $case = OffboardingCase::create([
            'employee_id' => $employee->id,
            'offboarding_program_id' => $program?->id,
            'type' => $type,
            'notice_date' => $attributes['notice_date'] ?? null,
            'last_working_day' => $attributes['last_working_day'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'status' => 'initiated',
        ]);

        if ($program) {
            self::seedFromProgram($case, $employee, $program);
        } else {
            self::seedStandardClearance($case, $employee);
        }

        return $case;
    }

    /**
     * Resolve the clearance template that best fits an exit, preferring the most
     * specific active match: department + exit type, then department, then exit
     * type, then a default (mirrors {@see OnboardingProvisioner::programFor}).
     */
    public static function programFor(Employee $employee, ?string $type = null): ?OffboardingProgram
    {
        $programs = OffboardingProgram::where('is_active', true)->get();

        if ($programs->isEmpty()) {
            return null;
        }

        $department = $employee->department_id;

        return $programs->first(fn (OffboardingProgram $p): bool => $p->department_id === $department && $p->exit_type === $type && $department !== null)
            ?? $programs->first(fn (OffboardingProgram $p): bool => $p->department_id === $department && $p->exit_type === null && $department !== null)
            ?? $programs->first(fn (OffboardingProgram $p): bool => $p->department_id === null && $p->exit_type === $type && $type !== null)
            ?? $programs->first(fn (OffboardingProgram $p): bool => $p->is_default)
            ?? null;
    }

    /**
     * Instantiate a program's blueprint items on a case, resolving "employee's own
     * department" items against the departing employee.
     */
    private static function seedFromProgram(OffboardingCase $case, Employee $employee, OffboardingProgram $program): void
    {
        foreach ($program->items()->get() as $index => $blueprint) {
            $case->clearanceItems()->create([
                'item' => $blueprint->item,
                'department_id' => $blueprint->use_employee_department
                    ? $employee->department_id
                    : $blueprint->department_id,
                'status' => 'pending',
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Instantiate the built-in standard checklist on a case, resolving each item's
     * owning department from its code (or the employee's own department).
     */
    private static function seedStandardClearance(OffboardingCase $case, Employee $employee): void
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
