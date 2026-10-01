<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\ReviewTemplate;
use Illuminate\Support\Collection;

/**
 * Decides which appraisal framework an employee is reviewed against.
 *
 * A company does not review a warehouse picker, a sales rep and an engineering
 * manager with the same form. Frameworks therefore carry an eligibility rule —
 * a department, a position, an employment type, or everyone — and this is the
 * one place it is read. The most specific match wins, because "everyone" is
 * meant as the fallback, not as a competitor.
 *
 * A resolved framework is only ever a *suggestion*: HR can always pick another
 * one when opening an appraisal.
 */
class TemplateResolver
{
    /** Narrowest rule first — a targeted framework beats the catch-all. */
    private const SPECIFICITY = ['position' => 0, 'department' => 1, 'employment_type' => 2, 'all' => 3];

    /**
     * The framework to review this employee with, or null when the tenant has
     * none that covers them.
     *
     * @param  Collection<int, ReviewTemplate>|null  $templates  Pre-loaded frameworks, to keep a bulk launch to one query.
     */
    public function forEmployee(Employee $employee, ?Collection $templates = null): ?ReviewTemplate
    {
        // One explicit comparator. `sortBy()` given a list of closures calls each
        // as a two-argument comparator, so one-argument key closures there never
        // ranked anything — the order came out of the sort, not these rules.
        $candidates = ($templates ?? $this->active())
            ->filter(fn (ReviewTemplate $template): bool => $template->coversEmployee($employee))
            ->sort(fn (ReviewTemplate $a, ReviewTemplate $b): int => $this->rank($a) <=> $this->rank($b));

        return $candidates->first();
    }

    /**
     * Where a framework stands among those covering somebody: the narrowest
     * rule first; within equal specificity, the tenant's own default; then the
     * oldest.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function rank(ReviewTemplate $template): array
    {
        return [self::SPECIFICITY[$template->applies_to] ?? 9, $template->is_default ? 0 : 1, (int) $template->id];
    }

    /**
     * Every framework available for new appraisals.
     *
     * @return Collection<int, ReviewTemplate>
     */
    public function active(): Collection
    {
        return ReviewTemplate::query()->active()->withCount('items')->catalogueOrder()->get();
    }
}
