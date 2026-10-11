<?php

namespace Database\Seeders;

use App\Models\ClearanceItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\Organization;
use App\Models\ResignationRequest;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use App\Support\OffboardingProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Demo offboarding: a spread of exits across the lifecycle (just initiated, in
 * clearance, flagged, completed and cancelled) with believable clearance
 * progress, so the board, the stats and the derived clearance status all read
 * naturally; exit interviews for them and for the recent past departures, so
 * Exit interviews has a year to read; and two resignations waiting for HR
 * (ADR 0076). Idempotent — each part only seeds when it has nothing yet.
 */
class OffboardingSeeder extends Seeder
{
    /**
     * The exit kinds applied to the seeded cases, in order.
     *
     * @var list<string>
     */
    private const TYPES = ['resignation', 'end_of_contract', 'termination', 'retirement', 'resignation'];

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        $this->seedDefaultProgram();
        $this->seedCases();
        $this->seedExitInterviews();
        $this->seedResignations();
    }

    private function seedCases(): void
    {
        // Idempotent on exits in flight: the completed ones the workforce history
        // leaves behind are the past, not the board's demo cases.
        if (OffboardingCase::query()->where('status', '!=', 'completed')->exists()) {
            return;
        }

        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('offboardingCase')
            ->inRandomOrder()
            ->limit(5)
            ->get();

        foreach ($employees as $i => $employee) {
            $type = self::TYPES[$i % count(self::TYPES)];

            $case = OffboardingProvisioner::start($employee, [
                'type' => $type,
                'notice_date' => now()->subDays(20 - $i * 3)->toDateString(),
                'last_working_day' => now()->addDays(10 + $i * 4)->toDateString(),
                'reason' => $this->reasonFor($type),
            ]);

            $this->varyProgress($case, $employee, $i);
        }
    }

    /**
     * Exit interviews: answered for the exits of the past year (a sample of the
     * workforce history's departures, and the completed demo case), waiting for
     * the ones in clearance. Terminations are not asked, as in the app.
     */
    private function seedExitInterviews(): void
    {
        if (ExitInterview::query()->exists()) {
            return;
        }

        $cases = OffboardingCase::query()
            ->where('type', '!=', 'termination')
            ->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('completed_at', '>=', now()->subYear()))
            ->latest('id')
            ->limit(40)
            ->get();

        foreach ($cases as $case) {
            $answered = $case->status === 'completed';
            $sent = ($case->completed_at ?? now())->copy()->subDays(fake()->numberBetween(3, 12));

            ExitInterview::create([
                'organization_id' => $case->organization_id,
                'offboarding_case_id' => $case->id,
                'employee_id' => $case->employee_id,
                'status' => $answered ? 'submitted' : 'pending',
                'questions' => ExitInterviewQuestionnaire::questions(),
                'answers' => $answered ? ExitInterviewQuestionnaire::sampleAnswers() : null,
                'sent_at' => $answered ? $sent : now()->subDays(fake()->numberBetween(1, 6)),
                'submitted_at' => $answered ? $sent->copy()->addDays(fake()->numberBetween(1, 3)) : null,
            ]);
        }

        // Somebody whose survey waited three days has been reminded already.
        ExitInterview::query()
            ->where('status', 'pending')
            ->where('sent_at', '<=', now()->subDays(ExitInterviews::REMIND_AFTER_DAYS))
            ->update(['reminded_at' => now()->subDay()]);
    }

    /**
     * Two resignations waiting for HR — one with a month's notice, one short —
     * from active people with no exit under way.
     */
    private function seedResignations(): void
    {
        if (ResignationRequest::query()->exists()) {
            return;
        }

        $people = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('offboardingCase')
            ->inRandomOrder()
            ->limit(2)
            ->get();

        foreach ($people as $i => $employee) {
            $filed = now()->subDays($i === 0 ? 2 : 6);

            $resignation = ResignationRequest::create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'user_id' => $employee->user_id,
                'proposed_last_day' => $filed->copy()->addDays($i === 0 ? 30 : 14)->toDateString(),
                'reason' => $i === 0
                    ? 'I have accepted an offer closer to home. Thank you for the past years.'
                    : 'Family reasons — I need to move back to the province within the month.',
                'status' => 'pending',
            ]);

            $resignation->forceFill(['created_at' => $filed, 'updated_at' => $filed])->save();
        }
    }

    /**
     * Seed the default clearance template from the provisioner's standard list,
     * so exits are template-driven out of the box and the Setup page isn't empty.
     * Idempotent — only seeds when no programs exist yet.
     */
    private function seedDefaultProgram(): void
    {
        if (OffboardingProgram::count() > 0) {
            return;
        }

        $program = OffboardingProgram::create([
            'name' => 'Standard Exit Clearance',
            'description' => 'The baseline clearance every departing employee goes through — IT, Finance, HR and their own department.',
            'is_default' => true,
            'is_active' => true,
        ]);

        $byCode = Department::query()
            ->get(['id', 'code'])
            ->keyBy(fn (Department $department): string => strtoupper((string) $department->code));

        foreach (OffboardingProvisioner::STANDARD_ITEMS as $index => $item) {
            $own = $item['department'] === '__own__';

            $program->items()->create([
                'item' => $item['item'],
                'department_id' => $own ? null : $byCode->get(strtoupper($item['department']))?->id,
                'use_employee_department' => $own,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Give each seeded case a different, believable clearance state.
     */
    private function varyProgress(OffboardingCase $case, Employee $employee, int $index): void
    {
        $items = $case->clearanceItems()->orderBy('sort_order')->get();

        match ($index) {
            // A finished exit — every item cleared, employee separated.
            0 => $this->complete($case, $employee, $items),
            // Roughly half signed off.
            1 => $this->clearSome($case, $items, (int) ceil($items->count() / 2)),
            // In progress with one outstanding issue flagged.
            2 => $this->flagOne($case, $items),
            // Cancelled — the exit was withdrawn.
            4 => $case->update(['status' => 'cancelled']),
            // Index 3 stays freshly "initiated" with nothing cleared yet.
            default => null,
        };
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function complete(OffboardingCase $case, Employee $employee, $items): void
    {
        $items->each->update(['status' => 'cleared', 'cleared_at' => now()]);
        $case->update(['status' => 'completed', 'completed_at' => now()]);
        $employee->update(['employment_status' => $case->targetEmploymentStatus()]);
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function clearSome(OffboardingCase $case, $items, int $count): void
    {
        $items->take($count)->each->update(['status' => 'cleared', 'cleared_at' => now()]);
        $case->update(['status' => 'clearance']);
    }

    /**
     * @param  Collection<int, ClearanceItem>  $items
     */
    private function flagOne(OffboardingCase $case, $items): void
    {
        $items->take(2)->each->update(['status' => 'cleared', 'cleared_at' => now()]);

        if ($flagged = $items->get(2)) {
            $flagged->update(['status' => 'flagged', 'remarks' => 'Pending return of company laptop.']);
        }

        $case->update(['status' => 'clearance']);
    }

    private function reasonFor(string $type): string
    {
        return match ($type) {
            'termination' => 'Separation following performance review.',
            'retirement' => 'Reached retirement age after long service.',
            'end_of_contract' => 'Fixed-term contract reached its end date.',
            default => 'Accepted a new opportunity outside the company.',
        };
    }
}
