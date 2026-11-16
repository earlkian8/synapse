<?php

namespace App\Http\Controllers\Discipline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discipline\ExplanationRequest;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Queries\DisciplineScreens;
use App\Support\Discipline\DisciplineDocuments;
use App\Support\Discipline\DisciplineException;
use App\Support\Discipline\DisciplineWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My notices (ADR 0105), `discipline.respond`: the cases served on oneself —
 * read the notice, acknowledge it, answer it in writing, ask for a hearing, and
 * read the decision. Only one's own, and only once served: anything else is a
 * 404, as if it weren't there.
 */
class MyDisciplineController extends Controller
{
    public function __construct(
        private readonly DisciplineWorkflow $workflow,
        private readonly DisciplineScreens $screens,
    ) {}

    public function index(Request $request): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('discipline/me', $employee !== null
            ? [...$this->screens->mine($employee), 'linked' => true]
            : ['record' => null, 'cases' => [], 'linked' => false]);
    }

    public function show(Request $request, DisciplinaryCase $disciplinaryCase): Response
    {
        $this->own($request, $disciplinaryCase);

        return Inertia::render('discipline/my-case', $this->screens->mineOne($disciplinaryCase));
    }

    public function acknowledge(Request $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->own($request, $disciplinaryCase);

        $notice = $request->input('notice') === 'decision' ? 'decision' : 'notice';

        try {
            $this->workflow->acknowledge($disciplinaryCase, $notice);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you. HR can see you received it.']);
        } catch (DisciplineException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);
        }

        return back();
    }

    public function explain(ExplanationRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->own($request, $disciplinaryCase);

        try {
            $this->workflow->explain($disciplinaryCase, $request->validated('explanation'), $request->boolean('hearing'), $request->user());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Your explanation was sent to HR.']);
        } catch (DisciplineException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);
        }

        return back();
    }

    public function noticePdf(Request $request, DisciplinaryCase $disciplinaryCase, DisciplineDocuments $documents): HttpResponse
    {
        $this->own($request, $disciplinaryCase);

        return $this->pdf($documents->notice($disciplinaryCase), DisciplineDocuments::filename('notice-to-explain', $disciplinaryCase));
    }

    public function decisionPdf(Request $request, DisciplinaryCase $disciplinaryCase, DisciplineDocuments $documents): HttpResponse
    {
        $this->own($request, $disciplinaryCase);
        abort_if($disciplinaryCase->decided_at === null, 404);

        return $this->pdf($documents->decision($disciplinaryCase), DisciplineDocuments::filename('notice-of-decision', $disciplinaryCase));
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee()->first();
    }

    /**
     * A case served on the signed-in person, or a 404.
     */
    private function own(Request $request, DisciplinaryCase $case): void
    {
        $employee = $this->employee($request);

        abort_unless($employee !== null && $case->employee_id === $employee->id && $case->notice_issued_at !== null, 404);
    }

    private function pdf(string $content, string $filename): HttpResponse
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
