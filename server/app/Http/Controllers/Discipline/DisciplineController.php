<?php

namespace App\Http\Controllers\Discipline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discipline\CaseAttachmentRequest;
use App\Http\Requests\Discipline\DecisionRequest;
use App\Http\Requests\Discipline\ExplanationRequest;
use App\Http\Requests\Discipline\HearingRequest;
use App\Http\Requests\Discipline\IssueNoticeRequest;
use App\Http\Requests\Discipline\PreventiveSuspensionRequest;
use App\Http\Requests\Discipline\RecordHearingRequest;
use App\Http\Requests\Discipline\ReportIncidentRequest;
use App\Http\Requests\Discipline\WithdrawCaseRequest;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\Employee;
use App\Queries\DisciplineScreens;
use App\Support\Discipline\DisciplineDocuments;
use App\Support\Discipline\DisciplineException;
use App\Support\Discipline\DisciplineWorkflow;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discipline (ADR 0105) — HR's side: every case, from the incident report
 * through the notice to explain, the explanation and a hearing, to the notice of
 * decision; each employee's conduct record. Seeing needs `discipline.view`,
 * every step `discipline.manage`; reporting an incident `discipline.report`.
 * Cases are addressed by hashid. Every step goes through
 * {@see DisciplineWorkflow}, which holds due process.
 */
class DisciplineController extends Controller
{
    public function __construct(
        private readonly DisciplineWorkflow $workflow,
        private readonly DisciplineScreens $screens,
    ) {}

    /**
     * One entry for the module: HR lands on the cases, a reporter on what they
     * filed, everyone else on the notices served on them.
     */
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->can('discipline.view') => to_route('discipline.cases.index'),
            $user->can('discipline.report') => to_route('discipline.reported'),
            $user->can('discipline.respond') => to_route('discipline.me.index'),
            default => abort(403),
        };
    }

    public function cases(Request $request): Response
    {
        return Inertia::render('discipline/cases', $this->screens->cases($request));
    }

    public function store(ReportIncidentRequest $request): RedirectResponse
    {
        $case = $this->workflow->report($request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Incident reported as {$case->reference}."]);

        return $request->user()->can('discipline.view')
            ? to_route('discipline.cases.show', $case)
            : to_route('discipline.reported');
    }

    public function show(Request $request, DisciplinaryCase $disciplinaryCase): Response
    {
        return Inertia::render('discipline/case', $this->screens->case($disciplinaryCase, $request));
    }

    public function update(ReportIncidentRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->update($disciplinaryCase, $request->validated()), 'Report saved.');
    }

    public function attach(CaseAttachmentRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        $this->workflow->attach($disciplinaryCase, $request->file('file'), $request->user());

        return $this->done('Evidence added.');
    }

    public function detach(DisciplinaryCaseAttachment $disciplinaryCaseAttachment): RedirectResponse
    {
        $this->workflow->detach($disciplinaryCaseAttachment);

        return $this->done('Evidence removed.');
    }

    public function notice(IssueNoticeRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->issueNotice(
            $disciplinaryCase,
            $request->validated('notice_grounds'),
            CarbonImmutable::parse($request->validated('reply_due_on')),
            $request->validated('conduct_offense_id'),
            $request->user(),
        ), 'Notice to explain served. The employee has been told.');
    }

    /**
     * An explanation handed in on paper, recorded by HR.
     */
    public function explanation(ExplanationRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->explain(
            $disciplinaryCase,
            $request->validated('explanation'),
            $request->boolean('hearing'),
            $request->user(),
        ), 'Explanation recorded.');
    }

    public function hearing(HearingRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->scheduleHearing(
            $disciplinaryCase,
            OrganizationClock::at(
                CarbonImmutable::parse($request->validated('hearing_at'))->toDateString(),
                CarbonImmutable::parse($request->validated('hearing_at'))->format('H:i'),
            ),
            $request->validated('hearing_place'),
            $request->user(),
        ), 'Hearing set. The employee has been told.');
    }

    public function recordHearing(RecordHearingRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->recordHearing(
            $disciplinaryCase,
            $request->boolean('attended'),
            $request->validated('hearing_notes'),
        ), 'Hearing recorded.');
    }

    public function preventive(PreventiveSuspensionRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->preventiveSuspension(
            $disciplinaryCase,
            CarbonImmutable::parse($request->validated('preventive_from')),
            (int) $request->validated('days'),
            $request->user(),
        ), 'Preventive suspension recorded. The employee has been told.');
    }

    public function decide(DecisionRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->decide($disciplinaryCase, $request->validated(), $request->user()),
            'Decided. The notice of decision has been served on the employee.');
    }

    public function close(DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->close($disciplinaryCase), 'Case closed.');
    }

    public function withdraw(WithdrawCaseRequest $request, DisciplinaryCase $disciplinaryCase): RedirectResponse
    {
        return $this->attempt(fn () => $this->workflow->withdraw($disciplinaryCase, $request->validated('withdrawn_reason'), $request->user()), 'Case withdrawn.');
    }

    public function noticePdf(DisciplinaryCase $disciplinaryCase, DisciplineDocuments $documents): HttpResponse
    {
        abort_if($disciplinaryCase->notice_issued_at === null, 404);

        return $this->pdf($documents->notice($disciplinaryCase), DisciplineDocuments::filename('notice-to-explain', $disciplinaryCase));
    }

    public function decisionPdf(DisciplinaryCase $disciplinaryCase, DisciplineDocuments $documents): HttpResponse
    {
        abort_if($disciplinaryCase->decided_at === null, 404);

        return $this->pdf($documents->decision($disciplinaryCase), DisciplineDocuments::filename('notice-of-decision', $disciplinaryCase));
    }

    /**
     * The reports somebody filed, and how far each got.
     */
    public function reported(Request $request): Response
    {
        return Inertia::render('discipline/reported', [
            'reports' => $this->screens->reported($request->user()),
            'options' => $this->screens->reportOptions(),
            'can' => ['view' => $request->user()->can('discipline.view')],
        ]);
    }

    /**
     * An employee's conduct record.
     */
    public function record(Employee $employee): Response
    {
        return Inertia::render('discipline/record', $this->screens->record($employee));
    }

    private function pdf(string $content, string $filename): HttpResponse
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function attempt(callable $step, string $message): RedirectResponse
    {
        try {
            $step();
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        } catch (DisciplineException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);
        }

        return back();
    }
}
