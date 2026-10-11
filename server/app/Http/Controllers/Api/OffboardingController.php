<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\AnswerExitInterviewRequest;
use App\Http\Requests\Offboarding\FileResignationRequest;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\ResignationRequest;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use App\Support\Offboarding\MyExit;
use App\Support\Offboarding\OffboardingDocuments;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\ResignationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Leaving, from the phone (ADRs 0076, 0077): the person's own exit as My
 * resignation shows it on the web ({@see MyExit}), filing and withdrawing a
 * resignation, the exit-interview survey, and a short-lived link to their
 * Certificate of Employment the phone opens in its browser. Self-scoped and
 * gated by `offboarding.resign`.
 */
class OffboardingController extends Controller
{
    /** How long a certificate link works. */
    private const LINK_MINUTES = 10;

    public function __construct(private readonly ResignationWorkflow $resignations) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => MyExit::for($this->employee($request))]);
    }

    public function resign(FileResignationRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return response()->json(['message' => 'Your account isn’t linked to an employee record. Ask HR to link it.'], 422);
        }

        try {
            $resignation = $this->resignations->file($employee, $request->user(), $request->validated());
        } catch (OffboardingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => MyExit::resignation($resignation)], 201);
    }

    public function withdraw(Request $request, ResignationRequest $resignation): JsonResponse
    {
        abort_unless($resignation->employee_id === $this->employee($request)?->id, 404);

        try {
            $this->resignations->withdraw($resignation, $request->user());
        } catch (OffboardingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => MyExit::resignation($resignation->refresh())]);
    }

    /**
     * The exit-interview survey: its questions, and the answers once given.
     */
    public function interview(Request $request): JsonResponse
    {
        $interview = $this->interviewFor($request);

        abort_if($interview === null, 404);

        return response()->json(['data' => [
            ...MyExit::interview($interview),
            'questions' => $interview->questions,
            'answers' => $interview->status === 'submitted' ? $interview->answers : null,
            'scale' => ExitInterviewQuestionnaire::SCALE,
        ]]);
    }

    public function answer(AnswerExitInterviewRequest $request, ExitInterviews $interviews): JsonResponse
    {
        $interview = $request->interview();

        abort_if($interview === null, 404);

        try {
            $interviews->submit($interview, $request->validated('answers'), $request->user());
        } catch (OffboardingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => MyExit::interview($interview->refresh())]);
    }

    /**
     * A link to the person's Certificate of Employment that works for a few
     * minutes without a token — the phone hands it to its browser, which shows,
     * shares and saves the PDF.
     */
    public function certificateLink(Request $request): JsonResponse
    {
        $case = $this->employee($request)?->offboardingCase()->first();

        if ($case === null || ! OffboardingDocuments::certificateReady($case)) {
            return response()->json(['message' => 'Your Certificate of Employment is ready once your exit is completed.'], 422);
        }

        $expires = now()->addMinutes(self::LINK_MINUTES);

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('api.documents.certificate', $expires, ['case' => $case->hashid, 'user' => $request->user()->id]),
            'expires_at' => $expires->toIso8601String(),
        ]]);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee()->first();
    }

    private function interviewFor(Request $request): ?ExitInterview
    {
        $employee = $this->employee($request);

        return $employee === null ? null : ExitInterview::query()
            ->where('employee_id', $employee->id)
            ->whereHas('offboardingCase', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->latest('id')
            ->first();
    }
}
