<?php

namespace App\Support\Performance;

use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\ReviewTemplateItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one path that opens an appraisal. Seeding a scorecard is not a copy of a
 * criteria list any more — it walks the framework's sections in order, resolves
 * the rating scale each item is actually measured on (its own, else the
 * criterion's, else the framework's), and **freezes all of it** onto the score
 * lines. That freeze is what lets a framework be retuned without disturbing an
 * appraisal already in flight.
 *
 * Both the single "open an evaluation" action and the bulk cycle launch come
 * through here, so a scorecard is built the same way however it was started.
 */
class EvaluationOpener
{
    /**
     * Open an appraisal for one employee, in one cycle, against one framework.
     * The caller is responsible for the eligibility checks (open period, no
     * duplicate) — see {@see canOpenFor()}.
     */
    public function open(
        Employee $employee,
        EvaluationPeriod $period,
        ReviewTemplate $template,
        ?User $evaluator = null,
    ): PerformanceEvaluation {
        $sections = collect($template->sectionList())->keyBy('key');
        $items = $template->items()->with(['ratingScale', 'criterion.ratingScale'])->get();

        // Lines are laid out section by section, in the framework's own order, so
        // the scorecard reads the way it was designed to read.
        $ordered = $items
            ->sortBy([
                fn (ReviewTemplateItem $item): int => $sections->keys()->search($item->section_key) === false
                    ? PHP_INT_MAX
                    : (int) $sections->keys()->search($item->section_key),
                fn (ReviewTemplateItem $item): int => $item->sort_order,
                fn (ReviewTemplateItem $item): int => $item->id,
            ])
            ->values();

        return DB::transaction(function () use ($employee, $period, $template, $evaluator, $sections, $ordered): PerformanceEvaluation {
            $evaluation = PerformanceEvaluation::create([
                'employee_id' => $employee->id,
                'evaluation_period_id' => $period->id,
                'review_template_id' => $template->id,
                'template_name' => $template->name,
                'template_sections' => $template->sectionList(),
                'template_bands' => $template->bandList(),
                'result_display' => $template->result_display,
                'evaluator_id' => $evaluator?->id,
                'status' => 'draft',
            ]);

            $evaluation->scores()->createMany(
                $ordered->map(function (ReviewTemplateItem $item, int $index) use ($sections, $template): array {
                    $section = $sections->get($item->section_key) ?? ReviewTemplate::fallbackSection();

                    return [
                        'kpi_criterion_id' => $item->kpi_criterion_id,
                        'review_template_item_id' => $item->id,
                        'label' => $item->name,
                        'description' => $item->description,
                        'section_key' => $section['key'],
                        'section_name' => $section['name'],
                        'section_weight' => $section['weight'],
                        'weight' => $item->weight,
                        'score' => null,
                        'sort_order' => $index,
                        ...$this->scaleFor($item, $template)->snapshot(),
                    ];
                })->all()
            );

            return $evaluation;
        });
    }

    /**
     * Why this employee cannot be appraised in this cycle — or null when they can.
     * Shared by the single-open action and the cycle launch, so both refuse for
     * the same reasons.
     */
    public function blockedReason(Employee $employee, EvaluationPeriod $period): ?string
    {
        if ($period->status !== 'open') {
            return 'The review cycle is not open.';
        }

        $exists = PerformanceEvaluation::query()
            ->where('employee_id', $employee->id)
            ->where('evaluation_period_id', $period->id)
            ->exists();

        return $exists ? 'Already appraised in this cycle.' : null;
    }

    /**
     * The scale an item is measured on: its own, else the one its catalogue
     * criterion carries, else the framework's default, else the standard 1–5.
     */
    private function scaleFor(ReviewTemplateItem $item, ReviewTemplate $template): RatingScale
    {
        return $item->ratingScale
            ?? $item->criterion?->ratingScale
            ?? $template->ratingScale
            ?? new RatingScale([
                'name' => '5-point rating',
                'type' => 'numeric',
                'min' => PerformanceScorer::RATING_MIN,
                'max' => PerformanceScorer::RATING_MAX,
                'step' => 1,
            ]);
    }
}
