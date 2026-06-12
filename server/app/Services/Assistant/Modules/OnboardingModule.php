<?php

namespace App\Services\Assistant\Modules;

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingProgram;
use App\Models\User;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\OnboardingProvisioner;

/**
 * Onboarding capability: find cases, start onboarding for an employee (from a
 * chosen or best-matching program), and advance a case's lifecycle. Reuses
 * {@see OnboardingProvisioner} so the checklist is seeded exactly as the hire
 * bridge and manual UI do.
 */
class OnboardingModule extends Module
{
    public function key(): string
    {
        return 'onboarding';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('onboarding.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_onboarding_cases' => 'findCases',
            'start_onboarding' => 'startOnboarding',
            'set_onboarding_status' => 'setStatus',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(): string
    {
        $programs = OnboardingProgram::where('is_active', true)->orderByDesc('is_default')->orderBy('name')
            ->get(['name', 'is_default'])
            ->map(fn (OnboardingProgram $p): string => $p->name.($p->is_default ? ' (default)' : ''))
            ->implode(', ') ?: 'none';

        return <<<TXT
        ONBOARDING — each employee's onboarding journey (a checklist with a lifecycle).
        - start_onboarding begins a case for an employee, seeding tasks from a program. If no program is named, the best-matching active program is used. An employee can only have one case.
        - set_onboarding_status advances a case: complete, cancel, or reopen.
        - Pass `employee` as a name or employee number and `program` as a program name.
          Programs: {$programs}
        TXT;
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'find_onboarding_cases',
                'description' => 'List onboarding cases, optionally filtered by employee name and/or status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Employee name or number.'],
                        'status' => ['type' => 'STRING', 'enum' => OnboardingCase::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'start_onboarding',
                'description' => 'Start an onboarding case for an employee, optionally from a named program.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'program' => ['type' => 'STRING', 'description' => 'Program name (optional).'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'set_onboarding_status',
                'description' => "Advance an employee's onboarding case: complete, cancel, or reopen it.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'action' => ['type' => 'STRING', 'enum' => ['complete', 'cancel', 'reopen']],
                    ],
                    'required' => ['employee', 'action'],
                ],
            ],
        ];
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCases(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'employee', 'match']));
        $status = $this->normaliseStatus($args['status'] ?? null);

        $cases = OnboardingCase::query()
            ->with(['employee.department', 'employee.position', 'program'])
            ->when($query !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $this->matchByTokens($e, $query)))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->limit(8)
            ->get();

        $cards = $cases->map(fn (OnboardingCase $c): array => $this->caseCard($c, 'find', 'neutral', $this->statusBadge($c->status)))->all();

        $label = $query !== '' ? "Searched onboarding for “{$query}”" : 'Listed onboarding cases';

        return ToolResult::found($label, count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function startOnboarding(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('start onboarding');
        }

        $employee = $this->locateEmployee($args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        if ($employee->onboardingCase()->exists()) {
            return ToolResult::error("Checked {$employee->full_name}", 'That employee is already being onboarded.');
        }

        $program = null;
        $programName = $this->firstFilled($args, ['program', 'program_name']);

        if ($programName !== null) {
            $program = OnboardingProgram::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($programName)])
                ->first();
        }

        $case = OnboardingProvisioner::start($employee, $program);
        $case->setRelation('employee', $employee)->setRelation('program', $program);

        ActivityLogger::log(
            event: 'created',
            description: "Started onboarding for {$employee->full_name} via assistant",
            subject: $case,
            properties: ['program' => $program?->name],
            logName: 'onboarding',
            subjectLabel: $employee->full_name,
        );

        return ToolResult::ok(
            "Started onboarding for {$employee->full_name}",
            $program?->name,
            $this->caseCard($case, 'start', 'positive', 'Started'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setStatus(User $user, array $args): ToolResult
    {
        if ($user->cannot('onboarding.manage')) {
            return $this->denied('manage onboarding');
        }

        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if (! in_array($action, ['complete', 'cancel', 'reopen'], true)) {
            return ToolResult::error('Updated onboarding', 'Action must be complete, cancel, or reopen.');
        }

        $employee = $this->locateEmployee($args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $case = OnboardingCase::query()
            ->with(['employee', 'program'])
            ->where('employee_id', $employee->id)
            ->latest()
            ->first();

        if (! $case) {
            return ToolResult::error("Checked {$employee->full_name}", 'That employee has no onboarding case.');
        }

        match ($action) {
            'complete' => $case->update(['status' => 'completed', 'completed_at' => now()]),
            'cancel' => $case->update(['status' => 'cancelled', 'completed_at' => null]),
            'reopen' => $case->update(['status' => 'in_progress', 'completed_at' => null]),
        };

        ActivityLogger::log(
            event: 'updated',
            description: ucfirst($action).'d onboarding for '.$case->employee->full_name.' via assistant',
            subject: $case,
            logName: 'onboarding',
            subjectLabel: $case->employee->full_name,
        );

        $tone = $action === 'cancel' ? 'warning' : ($action === 'complete' ? 'positive' : 'info');

        return ToolResult::ok(
            "Onboarding {$case->status} for {$case->employee->full_name}",
            null,
            $this->caseCard($case->fresh(['employee', 'program']), $action === 'complete' ? 'approve' : ($action === 'cancel' ? 'archive' : 'edit'), $tone, $this->statusBadge($case->status)),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateEmployee(array $args): ?Employee
    {
        $needle = $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']);

        return $needle ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    private function normaliseStatus(mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, OnboardingCase::STATUSES, true) ? $status : null;
    }

    private function statusBadge(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function caseCard(OnboardingCase $case, string $kind, string $tone, string $badge): array
    {
        $employee = $case->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: $case->program?->name ?? 'Onboarding',
            meta: [$this->statusBadge($case->status)],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $case->id,
        );
    }
}
