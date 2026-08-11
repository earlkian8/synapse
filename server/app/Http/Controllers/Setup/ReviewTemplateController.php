<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\ReviewTemplate;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Company Setup → Performance framework: the appraisal frameworks the
 * Performance module conducts reviews against — their weighted sections, the
 * items inside them, who they apply to, and the rating model their results are
 * reported in.
 *
 * A framework is saved whole: the items are replaced in one transaction rather
 * than diffed, because they only mean anything against the sections they were
 * submitted with. Appraisals already opened are untouched — they carry their own
 * snapshot.
 */
class ReviewTemplateController extends Controller
{
    public function store(ReviewTemplateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $template = DB::transaction(function () use ($data): ReviewTemplate {
            $template = ReviewTemplate::create($this->attributes($data));
            $this->replaceItems($template, $data['items']);

            return $template;
        });

        $this->settleDefault($template);

        ActivityLogger::log(
            event: 'created',
            description: "Created appraisal framework \"{$template->name}\"",
            subject: $template,
            logName: 'company-setup',
            subjectLabel: $template->name,
        );

        return $this->respond('Framework created.');
    }

    public function update(ReviewTemplateRequest $request, ReviewTemplate $reviewTemplate): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($reviewTemplate, $data): void {
            $reviewTemplate->update($this->attributes($data));
            $this->replaceItems($reviewTemplate, $data['items']);
        });

        $this->settleDefault($reviewTemplate);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated appraisal framework \"{$reviewTemplate->name}\"",
            subject: $reviewTemplate,
            logName: 'company-setup',
            subjectLabel: $reviewTemplate->name,
        );

        return $this->respond('Framework updated.');
    }

    public function destroy(ReviewTemplate $reviewTemplate): RedirectResponse
    {
        $name = $reviewTemplate->name;
        $reviewTemplate->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived appraisal framework \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Framework archived.');
    }

    public function restore(string $reviewTemplate): RedirectResponse
    {
        $model = $this->findTrashed($reviewTemplate);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored appraisal framework \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Framework restored.');
    }

    public function forceDelete(string $reviewTemplate): RedirectResponse
    {
        $model = $this->findTrashed($reviewTemplate);

        if ($model->evaluations()->exists()) {
            return $this->respond('This framework has been used for appraisals and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted appraisal framework \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Framework permanently deleted.');
    }

    /**
     * The framework's own columns — everything but its items.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
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
     * Replace the framework's items with what was submitted, in the order it was
     * submitted in. Order is the reading order of the scorecard, so it is the
     * editor's list position rather than anything the client has to number.
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
    private function settleDefault(ReviewTemplate $template): void
    {
        if (! $template->is_default) {
            return;
        }

        ReviewTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
    }

    private function findTrashed(string $hashid): ReviewTemplate
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return ReviewTemplate::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
