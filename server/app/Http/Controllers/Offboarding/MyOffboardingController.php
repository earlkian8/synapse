<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\AnswerExitInterviewRequest;
use App\Http\Requests\Offboarding\FileResignationRequest;
use App\Models\ExitInterview;
use App\Models\ResignationRequest;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use App\Support\Offboarding\MyExit;
use App\Support\Offboarding\OffboardingDocuments;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\ResignationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Leaving, for the person who is (ADR 0076): **My resignation** — file one,
 * withdraw it while it waits, follow the exit and its clearance once HR accepts
 * it — the exit-interview survey, and their Certificate of Employment once the
 * exit is completed (ADR 0077). Gated by `offboarding.resign`; somebody else's
 * resignation is a 404 here.
 */
class MyOffboardingController extends Controller
{
    use OffboardingResponses;

    public function __construct(private readonly ResignationWorkflow $resignations) {}

    public function index(Request $request): Response
    {
        return Inertia::render('offboarding/me', [
            ...MyExit::for($this->ownEmployee($request)),
            'nav' => $this->navCounts($request),
        ]);
    }

    public function resign(FileResignationRequest $request): RedirectResponse
    {
        $employee = $this->ownEmployee($request);

        if ($employee === null) {
            return $this->toast('Your account isn’t linked to an employee record. Ask HR to link it.', 'warning');
        }

        return $this->attempt(
            fn () => $this->resignations->file($employee, $request->user(), $request->validated()),
            'Resignation sent to HR.',
        );
    }

    public function withdraw(Request $request, ResignationRequest $resignation): RedirectResponse
    {
        abort_unless($resignation->employee_id === $this->ownEmployee($request)?->id, 404);

        return $this->attempt(
            fn () => $this->resignations->withdraw($resignation, $request->user()),
            'Resignation withdrawn.',
        );
    }

    /**
     * The exit-interview survey: the questions to answer, or the answers given.
     */
    public function interview(Request $request): Response|RedirectResponse
    {
        $interview = $this->ownInterview($request);

        if ($interview === null) {
            return redirect()->route('offboarding.me');
        }

        return Inertia::render('offboarding/exit-interview', [
            'interview' => [
                ...MyExit::interview($interview),
                'questions' => $interview->questions,
                'answers' => $interview->status === 'submitted' ? $interview->answers : null,
                'scale' => ExitInterviewQuestionnaire::SCALE,
            ],
            'nav' => $this->navCounts($request),
        ]);
    }

    public function answer(AnswerExitInterviewRequest $request, ExitInterviews $interviews): RedirectResponse
    {
        $interview = $request->interview();

        if ($interview === null) {
            return $this->toast('There is no exit interview waiting for you.', 'warning');
        }

        try {
            $interviews->submit($interview, $request->validated('answers'), $request->user());
        } catch (OffboardingException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thank you. Your answers went to HR.']);

        return redirect()->route('offboarding.me');
    }

    /**
     * Their Certificate of Employment, once the exit is completed.
     */
    public function certificate(Request $request, OffboardingDocuments $documents): HttpResponse|RedirectResponse
    {
        $case = $this->ownEmployee($request)?->offboardingCase()->first();

        abort_if($case === null, 404);

        try {
            $pdf = $documents->certificate($case, $request->user());
        } catch (OffboardingException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        return OffboardingDocuments::respond($pdf, OffboardingDocuments::filename('certificate-of-employment', $case));
    }

    private function ownInterview(Request $request): ?ExitInterview
    {
        $employee = $this->ownEmployee($request);

        return $employee === null ? null : ExitInterview::query()
            ->where('employee_id', $employee->id)
            ->whereHas('offboardingCase', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->first();
    }
}
