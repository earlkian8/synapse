<?php

namespace App\Support\Training;

use App\Models\TrainingBudget;
use App\Models\TrainingCost;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\OrganizationClock;
use Illuminate\Support\Collection;

/**
 * What training costs (ADR 0075): the costs recorded against each program, a
 * program's own budget, and the organisation's budget for each calendar year.
 *
 * Nothing spent is stored as a total. A program's spend is the sum of its
 * costs; a year's spend is the sum of the costs incurred in it, archived
 * programs included — money spent stays spent. Cost per participant divides a
 * program's spend by the people holding a seat; cost per completion by those who
 * completed.
 */
class TrainingBudgets
{
    /**
     * @param  array{category: string, description?: string|null, amount: float|int|string, incurred_on: string}  $data
     */
    public function record(TrainingProgram $program, array $data, ?User $by, string $channel = ''): TrainingCost
    {
        $cost = $program->costs()->create([
            ...$this->costFields($data),
            'recorded_by' => $by?->id,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: 'Recorded a '.self::money((float) $cost->amount)." cost on {$program->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $cost;
    }

    /**
     * @param  array{category: string, description?: string|null, amount: float|int|string, incurred_on: string}  $data
     */
    public function update(TrainingCost $cost, array $data, string $channel = ''): TrainingCost
    {
        $cost->update($this->costFields($data));
        $program = $cost->program;

        ActivityLogger::log(
            event: 'updated',
            description: "Updated a cost on {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );

        return $cost;
    }

    public function delete(TrainingCost $cost, string $channel = ''): void
    {
        $program = $cost->program;
        $amount = self::money((float) $cost->amount);
        $cost->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Removed a {$amount} cost from {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );
    }

    /**
     * Set the organisation's training budget for a year (or change it).
     */
    public function setBudget(int $year, float $amount, ?string $notes, string $channel = ''): TrainingBudget
    {
        $budget = TrainingBudget::query()->updateOrCreate(
            ['year' => $year],
            ['amount' => round($amount, 2), 'notes' => filled($notes) ? trim((string) $notes) : null],
        );

        ActivityLogger::log(
            event: $budget->wasRecentlyCreated ? 'created' : 'updated',
            description: "Set the {$year} training budget to ".self::money($amount).$channel,
            logName: 'training',
            subjectLabel: "Training budget {$year}",
        );

        return $budget;
    }

    /**
     * One program's money: its budget, what has been spent and what is left,
     * spend per seat and per completion, and spend by category.
     *
     * @return array{budget: float|null, spent: float, remaining: float|null, over_budget: bool, used_percent: int|null, per_participant: float|null, per_completion: float|null, entries: int, by_category: array<string, float>}
     */
    public static function forProgram(TrainingProgram $program): array
    {
        $costs = $program->relationLoaded('costs') ? $program->costs : $program->costs()->get(['category', 'amount']);
        $spent = round((float) $costs->sum(fn (TrainingCost $c): float => (float) $c->amount), 2);
        $budget = $program->budget === null ? null : (float) $program->budget;

        $seats = TrainingEnrollment::query()->where('training_program_id', $program->id)->active()->count();
        $completed = TrainingEnrollment::query()->where('training_program_id', $program->id)->where('status', 'completed')->count();

        return [
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget === null ? null : round($budget - $spent, 2),
            'over_budget' => $budget !== null && $spent > $budget,
            'used_percent' => $budget === null || $budget <= 0 ? null : (int) round($spent / $budget * 100),
            'per_participant' => $seats === 0 || $spent === 0.0 ? null : round($spent / $seats, 2),
            'per_completion' => $completed === 0 || $spent === 0.0 ? null : round($spent / $completed, 2),
            'entries' => $costs->count(),
            'by_category' => self::byCategory($costs),
        ];
    }

    /**
     * A year's training money: the budget set for it, what was spent in it (by
     * category, by month and by program), and what programs starting that year
     * were given as their own budgets.
     *
     * @return array{year: int, budget: float|null, notes: string|null, spent: float, remaining: float|null, used_percent: int|null, allocated: float, by_category: array<string, float>, by_month: list<float>, programs: list<array{id: int, hashid: string, name: string, archived: bool, status: string, budget: float|null, spent: float, spent_total: float}>}
     */
    public static function forYear(int $year): array
    {
        $budget = TrainingBudget::query()->where('year', $year)->first();

        $costs = TrainingCost::query()
            ->whereBetween('incurred_on', ["{$year}-01-01", "{$year}-12-31"])
            ->get(['training_program_id', 'category', 'amount', 'incurred_on']);

        $spent = round((float) $costs->sum(fn (TrainingCost $c): float => (float) $c->amount), 2);
        $amount = $budget === null ? null : (float) $budget->amount;

        $byMonth = array_fill(0, 12, 0.0);

        foreach ($costs as $cost) {
            $byMonth[$cost->incurred_on->month - 1] += (float) $cost->amount;
        }

        // Programs that spent this year, or that start this year.
        $programs = TrainingProgram::withTrashed()
            ->where(fn ($query) => $query
                ->whereIn('id', $costs->pluck('training_program_id')->unique())
                ->orWhereBetween('start_date', ["{$year}-01-01", "{$year}-12-31"]))
            ->withSum('costs as spent_total', 'amount')
            ->get();

        $spentInYear = $costs->groupBy('training_program_id')->map(fn (Collection $c): float => round((float) $c->sum(fn (TrainingCost $cost): float => (float) $cost->amount), 2));

        $allocated = round((float) $programs
            ->filter(fn (TrainingProgram $p): bool => $p->start_date !== null && $p->start_date->year === $year && ! $p->trashed())
            ->sum(fn (TrainingProgram $p): float => (float) ($p->budget ?? 0)), 2);

        return [
            'year' => $year,
            'budget' => $amount,
            'notes' => $budget?->notes,
            'spent' => $spent,
            'remaining' => $amount === null ? null : round($amount - $spent, 2),
            'used_percent' => $amount === null || $amount <= 0 ? null : (int) round($spent / $amount * 100),
            'allocated' => $allocated,
            'by_category' => self::byCategory($costs),
            'by_month' => array_map(fn (float $v): float => round($v, 2), $byMonth),
            'programs' => $programs
                ->map(fn (TrainingProgram $p): array => [
                    'id' => $p->id,
                    'hashid' => $p->hashid,
                    'name' => $p->name,
                    'archived' => $p->trashed(),
                    'status' => $p->status(),
                    'budget' => $p->budget === null ? null : (float) $p->budget,
                    'spent' => $spentInYear->get($p->id, 0.0),
                    'spent_total' => round((float) ($p->spent_total ?? 0), 2),
                ])
                ->sortByDesc('spent')
                ->values()
                ->all(),
        ];
    }

    /**
     * The years worth offering: every year with a budget or a cost, and this one.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        $current = (int) substr(OrganizationClock::today(), 0, 4);

        $fromCosts = TrainingCost::query()->pluck('incurred_on')->map(fn ($date): int => (int) $date->year);
        $fromBudgets = TrainingBudget::query()->pluck('year');

        return $fromCosts->merge($fromBudgets)->push($current)
            ->map(fn (mixed $y): int => (int) $y)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /** "₱12,500.00" — the peso, as the rest of the app shows money. */
    public static function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    /**
     * Spend per category, every category present, largest first.
     *
     * @param  Collection<int, TrainingCost>  $costs
     * @return array<string, float>
     */
    private static function byCategory(Collection $costs): array
    {
        return $costs->groupBy('category')
            ->map(fn (Collection $c): float => round((float) $c->sum(fn (TrainingCost $cost): float => (float) $cost->amount), 2))
            ->sortDesc()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function costFields(array $data): array
    {
        return [
            'category' => $data['category'],
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'amount' => round((float) $data['amount'], 2),
            'incurred_on' => $data['incurred_on'],
        ];
    }
}
