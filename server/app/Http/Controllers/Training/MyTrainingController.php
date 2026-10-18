<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingCertificateResource;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Queries\MyTraining;
use App\Support\Training\TrainingSelfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training for the person taking it (ADR 0074): **My training** — their
 * sessions coming up, their programs with how each is going, and the
 * certificates they have earned — and **Browse**, the programs they may join
 * or ask to join. Gated by `training.participate`; someone else's enrollment
 * or session is a 404 here. {@see MyTraining} reads it all, for the app too.
 */
class MyTrainingController extends Controller
{
    use TrainingResponses;

    public function __construct(
        private readonly TrainingSelfService $selfService,
        private readonly MyTraining $training,
    ) {}

    public function index(Request $request): Response
    {
        $employee = $this->ownEmployee($request);
        $enrollments = $this->training->enrollments($employee);

        return Inertia::render('training/me', [
            'enrollments' => $enrollments->map(fn (TrainingEnrollment $e): array => MyTraining::enrollment($e))->values()->all(),
            'sessions' => $this->training->upcomingSessions($enrollments)->map(fn (TrainingSession $s): array => MyTraining::session($s))->values()->all(),
            'certificates' => TrainingCertificateResource::listing($this->training->certificates($employee), $request),
            'tab' => in_array($request->query('tab'), ['sessions', 'programs', 'certificates'], true) ? $request->query('tab') : null,
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request),
        ]);
    }

    /**
     * The programs the person may join or ask to join — open to self-enrolment,
     * not over — with where they stand on each. Shown as a participant sees a
     * program: no budget, no AI notes.
     */
    public function catalog(Request $request): Response
    {
        $employee = $this->ownEmployee($request);
        $programs = $this->training->catalog();
        $mine = $this->training->standings($employee, $programs);

        return Inertia::render('training/catalog', [
            'programs' => $programs->map(fn (TrainingProgram $p): array => [
                ...MyTraining::listing($p),
                'mine' => $mine->has($p->id) ? [
                    'id' => $mine[$p->id]->id,
                    'status' => $mine[$p->id]->status,
                    'decision_note' => $mine[$p->id]->decision_note,
                ] : null,
            ])->all(),
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request),
        ]);
    }

    public function join(Request $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        $employee = $this->ownEmployee($request);
        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $this->attempt(
            fn () => $this->selfService->join($trainingProgram, $employee, $request->user()),
            fn (TrainingEnrollment $e): string => $e->status === 'enrolled'
                ? "You’re in. {$trainingProgram->name} is on My training."
                : 'Request sent. You’ll be told when HR decides.',
        );
    }

    public function withdraw(Request $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        $this->assertOwn($request, $enrollment);
        $requested = $enrollment->status === 'requested';

        return $this->attempt(
            fn () => $this->selfService->withdraw($enrollment),
            $requested ? 'Request withdrawn.' : 'You’ve left the program.',
        );
    }

    /**
     * One of the person's sessions as a calendar file.
     */
    public function sessionIcs(Request $request, TrainingSession $session): HttpResponse
    {
        abort_unless($this->training->holdsSeat($session, $this->ownEmployee($request)), 404);

        [$body, $filename] = MyTraining::calendarFile($session);

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function assertOwn(Request $request, TrainingEnrollment $enrollment): void
    {
        abort_unless($enrollment->employee_id === $this->ownEmployee($request)?->id, 404);
    }
}
