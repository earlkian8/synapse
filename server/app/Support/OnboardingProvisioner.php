<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use Illuminate\Support\Carbon;

/**
 * Starts an employee's onboarding: picks the most appropriate active program and
 * instantiates it into a {@see OnboardingCase} with concrete, dated tasks.
 *
 * This is the connective tissue between Recruitment and the workforce — the hire
 * bridge calls it so every new employee lands with a ready checklist (ADR 0007).
 * It is idempotent per employee: an employee already onboarding is returned as-is.
 */
class OnboardingProvisioner
{
    /**
     * Begin onboarding for an employee, seeding tasks from the given (or best-matching)
     * program. Returns the existing case if the employee already has one.
     */
    public static function start(Employee $employee, ?OnboardingProgram $program = null, ?Carbon $startDate = null): OnboardingCase
    {
        if ($existing = $employee->onboardingCase()->first()) {
            return $existing;
        }

        $program ??= self::programFor($employee);
        $start = $startDate
            ?? ($employee->date_hired ? Carbon::parse($employee->date_hired) : Carbon::today());

        $tasks = $program?->tasks()->get() ?? collect();

        $targetEnd = $tasks->isNotEmpty()
            ? (clone $start)->addDays((int) $tasks->max('due_offset_days'))
            : null;

        $case = OnboardingCase::create([
            'employee_id' => $employee->id,
            'onboarding_program_id' => $program?->id,
            'status' => 'pending',
            'start_date' => $start->toDateString(),
            'target_end_date' => $targetEnd?->toDateString(),
        ]);

        foreach ($tasks as $blueprint) {
            $case->tasks()->create([
                'title' => $blueprint->title,
                'description' => $blueprint->description,
                'category' => $blueprint->category,
                'due_date' => (clone $start)->addDays($blueprint->due_offset_days)->toDateString(),
                'status' => 'pending',
                'sort_order' => $blueprint->sort_order,
            ]);
        }

        return $case;
    }

    /**
     * Resolve the program that best fits an employee, preferring the most specific
     * active match: department + type, then department, then type, then a default.
     */
    public static function programFor(Employee $employee): ?OnboardingProgram
    {
        $programs = OnboardingProgram::where('is_active', true)->get();

        if ($programs->isEmpty()) {
            return null;
        }

        $department = $employee->department_id;
        $type = $employee->employment_type;

        return $programs->first(fn (OnboardingProgram $p): bool => $p->department_id === $department && $p->employment_type === $type && $department !== null)
            ?? $programs->first(fn (OnboardingProgram $p): bool => $p->department_id === $department && $p->employment_type === null && $department !== null)
            ?? $programs->first(fn (OnboardingProgram $p): bool => $p->department_id === null && $p->employment_type === $type && $type !== null)
            ?? $programs->first(fn (OnboardingProgram $p): bool => $p->is_default)
            ?? null;
    }
}
