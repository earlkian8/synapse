<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\ReviewTemplate;
use App\Support\Hashid;
use App\Support\Setup\PerformanceFrameworkException;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use Illuminate\Http\RedirectResponse;
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
 * snapshot. Thin controller: every write is {@see PerformanceFrameworkWorkflow},
 * which the assistant uses too.
 */
class ReviewTemplateController extends Controller
{
    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function store(ReviewTemplateRequest $request): RedirectResponse
    {
        $this->workflow->saveFramework(null, $request->validated());

        return $this->respond('Framework created.');
    }

    public function update(ReviewTemplateRequest $request, ReviewTemplate $reviewTemplate): RedirectResponse
    {
        $this->workflow->saveFramework($reviewTemplate, $request->validated());

        return $this->respond('Framework updated.');
    }

    public function destroy(ReviewTemplate $reviewTemplate): RedirectResponse
    {
        $this->workflow->archiveFramework($reviewTemplate);

        return $this->respond('Framework archived.');
    }

    public function restore(string $reviewTemplate): RedirectResponse
    {
        $this->workflow->restoreFramework($this->findTrashed($reviewTemplate));

        return $this->respond('Framework restored.');
    }

    public function forceDelete(string $reviewTemplate): RedirectResponse
    {
        try {
            $this->workflow->forceDeleteFramework($this->findTrashed($reviewTemplate));
        } catch (PerformanceFrameworkException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Framework permanently deleted.');
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
