<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\EvaluationPeriodRequest;
use App\Http\Requests\Setup\KpiCriterionRequest;
use App\Http\Requests\Setup\RatingScaleRequest;
use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the performance framework (ADR 0028): the appraisal
 * frameworks, the rating scales they measure on, the criteria catalogue they
 * draw from, and the review cycles they run in.
 *
 * The Performance framework screen and the assistant both come through here,
 * so each is written and recorded the same way whoever asked. Validation is the
 * screen's own requests ({@see ReviewTemplateRequest},
 * {@see RatingScaleRequest}, {@see KpiCriterionRequest},
 * {@see EvaluationPeriodRequest}), which both run first. Refusals are
 * {@see PerformanceFrameworkException}, worded to be shown as they are.
 *
 * A framework is saved whole: its items are replaced in one transaction rather
 * than diffed, because they only mean anything against the sections they were
 * saved with. Appraisals already opened are untouched — they carry their own
 * snapshot — so every change here reaches the next appraisal, never a past one.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class PerformanceFrameworkWorkflow
{
    // ── Frameworks ───────────────────────────────────────────────────────────

    /**
     * Create a framework (null) or replace this one, from a document that has
     * been through {@see ReviewTemplateRequest::normalise()} and validated.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveFramework(?ReviewTemplate $template, array $data, string $channel = ''): ReviewTemplate
    {
        $creating = $template === null;

        $template = DB::transaction(function () use ($template, $data): ReviewTemplate {
            $template ??= new ReviewTemplate;
            $template->fill($this->frameworkAttributes($data))->save();
            $this->replaceItems($template, $data['items']);

            return $template;
        });

        $this->settleDefaultFramework($template);

        $this->log(
            $creating ? 'created' : 'updated',
            ($creating ? 'Created' : 'Updated')." appraisal framework \"{$template->name}\"{$channel}",
            $template,
        );

        return $template;
    }

    /**
     * Make a framework the default — the one that wins among frameworks equally
     * specific to somebody.
     */
    public function setDefaultFramework(ReviewTemplate $template, string $channel = ''): void
    {
        $template->forceFill(['is_default' => true])->save();
        $this->settleDefaultFramework($template);

        $this->log('updated', "Set \"{$template->name}\" as the default appraisal framework{$channel}", $template);
    }

    public function archiveFramework(ReviewTemplate $template, string $channel = ''): void
    {
        $name = $template->name;
        $template->delete();

        $this->log('archived', "Archived appraisal framework \"{$name}\"{$channel}", null, $name);
    }

    public function restoreFramework(ReviewTemplate $template, string $channel = ''): void
    {
        $template->restore();

        $this->log('restored', "Restored appraisal framework \"{$template->name}\"{$channel}", $template);
    }

    /**
     * @throws PerformanceFrameworkException once it has been used for an appraisal
     */
    public function forceDeleteFramework(ReviewTemplate $template, string $channel = ''): void
    {
        if ($template->evaluations()->exists()) {
            throw new PerformanceFrameworkException('This framework has been used for appraisals and cannot be permanently deleted.');
        }

        $name = $template->name;
        $template->forceDelete();

        $this->log('deleted', "Permanently deleted appraisal framework \"{$name}\"{$channel}", null, $name);
    }

    // ── Rating scales ────────────────────────────────────────────────────────

    /**
     * Create a scale (null) or change this one, from values that have been
     * through {@see RatingScaleRequest::normalise()} and validated.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveScale(?RatingScale $scale, array $data, string $channel = ''): RatingScale
    {
        $creating = $scale === null;
        $scale ??= new RatingScale;
        $scale->fill($data)->save();

        if ($scale->is_default) {
            RatingScale::query()->whereKeyNot($scale->id)->update(['is_default' => false]);
        }

        $this->log($creating ? 'created' : 'updated', ($creating ? 'Created' : 'Updated')." rating scale \"{$scale->name}\"{$channel}", $scale);

        return $scale;
    }

    public function archiveScale(RatingScale $scale, string $channel = ''): void
    {
        $name = $scale->name;
        $scale->delete();

        $this->log('archived', "Archived rating scale \"{$name}\"{$channel}", null, $name);
    }

    public function restoreScale(RatingScale $scale, string $channel = ''): void
    {
        $scale->restore();

        $this->log('restored', "Restored rating scale \"{$scale->name}\"{$channel}", $scale);
    }

    /**
     * @throws PerformanceFrameworkException while a criterion or a framework line uses it
     */
    public function forceDeleteScale(RatingScale $scale, string $channel = ''): void
    {
        if ($scale->criteria()->exists() || $scale->items()->exists()) {
            throw new PerformanceFrameworkException('This scale is still in use and cannot be permanently deleted.');
        }

        $name = $scale->name;
        $scale->forceDelete();

        $this->log('deleted', "Permanently deleted rating scale \"{$name}\"{$channel}", null, $name);
    }

    // ── Criteria ─────────────────────────────────────────────────────────────

    /**
     * Create a catalogue criterion (null) or change this one.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveCriterion(?KpiCriterion $criterion, array $data, string $channel = ''): KpiCriterion
    {
        $creating = $criterion === null;
        $criterion ??= new KpiCriterion;
        $criterion->fill($data)->save();

        $this->log($creating ? 'created' : 'updated', ($creating ? 'Created' : 'Updated')." KPI criterion \"{$criterion->name}\"{$channel}", $criterion);

        return $criterion;
    }

    public function archiveCriterion(KpiCriterion $criterion, string $channel = ''): void
    {
        $name = $criterion->name;
        $criterion->delete();

        $this->log('archived', "Archived KPI criterion \"{$name}\"{$channel}", null, $name);
    }

    public function restoreCriterion(KpiCriterion $criterion, string $channel = ''): void
    {
        $criterion->restore();

        $this->log('restored', "Restored KPI criterion \"{$criterion->name}\"{$channel}", $criterion);
    }

    /**
     * @throws PerformanceFrameworkException while a framework or an appraisal uses it
     */
    public function forceDeleteCriterion(KpiCriterion $criterion, string $channel = ''): void
    {
        if ($criterion->templateItems()->exists() || $criterion->scores()->exists()) {
            throw new PerformanceFrameworkException('This criterion is used by a framework or an appraisal and cannot be permanently deleted.');
        }

        $name = $criterion->name;
        $criterion->forceDelete();

        $this->log('deleted', "Permanently deleted KPI criterion \"{$name}\"{$channel}", null, $name);
    }

    // ── Review cycles ────────────────────────────────────────────────────────

    /**
     * Create a review cycle (null) or change this one.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePeriod(?EvaluationPeriod $period, array $data, string $channel = ''): EvaluationPeriod
    {
        $creating = $period === null;
        $period ??= new EvaluationPeriod;
        $period->fill($data)->save();

        $this->log($creating ? 'created' : 'updated', ($creating ? 'Created' : 'Updated')." evaluation period \"{$period->name}\"{$channel}", $period);

        return $period;
    }

    public function archivePeriod(EvaluationPeriod $period, string $channel = ''): void
    {
        $name = $period->name;
        $period->delete();

        $this->log('archived', "Archived evaluation period \"{$name}\"{$channel}", null, $name);
    }

    public function restorePeriod(EvaluationPeriod $period, string $channel = ''): void
    {
        $period->restore();

        $this->log('restored', "Restored evaluation period \"{$period->name}\"{$channel}", $period);
    }

    /**
     * @throws PerformanceFrameworkException while appraisals were run in it
     */
    public function forceDeletePeriod(EvaluationPeriod $period, string $channel = ''): void
    {
        if ($period->evaluations()->exists()) {
            throw new PerformanceFrameworkException('This period has evaluations and cannot be permanently deleted.');
        }

        $name = $period->name;
        $period->forceDelete();

        $this->log('deleted', "Permanently deleted evaluation period \"{$name}\"{$channel}", null, $name);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The framework's own columns — everything but its items.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function frameworkAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rating_scale_id' => $data['rating_scale_id'] ?? null,
            'sections' => $data['sections'],
            'bands' => $data['bands'],
            'result_display' => $data['result_display'],
            'applies_to' => $data['applies_to'],
            'applies_to_values' => $data['applies_to'] === 'all' ? null : ($data['applies_to_values'] ?? []),
            'is_default' => $data['is_default'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * Replace the framework's items with what was given, in the order given.
     * Order is the reading order of the scorecard, so it is the list position
     * rather than anything a caller has to number.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(ReviewTemplate $template, array $items): void
    {
        $template->items()->delete();

        $template->items()->createMany(
            array_map(fn (array $item, int $index): array => [
                'kpi_criterion_id' => $item['kpi_criterion_id'] ?? null,
                'rating_scale_id' => $item['rating_scale_id'] ?? null,
                'section_key' => $item['section_key'],
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'weight' => $item['weight'],
                'sort_order' => $index,
            ], $items, array_keys($items))
        );
    }

    /**
     * A tenant has one default framework, so promoting one demotes the rest.
     */
    private function settleDefaultFramework(ReviewTemplate $template): void
    {
        if (! $template->is_default) {
            return;
        }

        ReviewTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
    }

    private function log(string $event, string $description, ?Model $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'company-setup',
            subjectLabel: $label ?? $subject?->getAttribute('name'),
        );
    }
}
