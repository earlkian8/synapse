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
        $candidates = ($templates ?? $this->active())
            ->filter(fn (ReviewTemplate $template): bool => $template->coversEmployee($employee))
            ->sortBy([
                fn (ReviewTemplate $template): int => self::SPECIFICITY[$template->applies_to] ?? 9,
                // Within equal specificity, the tenant's own default is the answer.
                fn (ReviewTemplate $template): int => $template->is_default ? 0 : 1,
                fn (ReviewTemplate $template): int => $template->id,
            ]);

        return $candidates->first();
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
