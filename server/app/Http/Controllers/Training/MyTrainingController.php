<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingCertificateResource;
use App\Http\Resources\TrainingProgramResource;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\Events\EventCalendar;
use App\Support\OrganizationClock;
use App\Support\Training\TrainingCertificates;
use App\Support\Training\TrainingSelfService;
use App\Support\Training\TrainingSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training for the person taking it (ADR 0074): **My training** — their
 * sessions coming up, their programs with how each is going, and the
 * certificates they have earned — and **Browse**, the programs they may join
 * or ask to join. Gated by `training.participate`; someone else's enrollment
 * or session is a 404 here.
 */
class MyTrainingController extends Controller
{
    use TrainingResponses;

    /** How far ahead "coming up" looks. */
    private const AHEAD_DAYS = 120;

    public function __construct(private readonly TrainingSelfService $selfService) {}

    public function index(Request $request): Response
    {
        $employee = $this->ownEmployee($request);
        $today = OrganizationClock::today();

        $enrollments = $employee === null ? collect() : TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereHas('program')
            ->with(['program', 'certificate:id,training_enrollment_id,certificate_no,expiry_date', 'attendances:id,training_enrollment_id,status'])
            ->latest('id')
            ->get();

        $seated = $enrollments->filter(fn (TrainingEnrollment $e): bool => in_array($e->status, TrainingEnrollment::SEATED_STATUSES, true));

        $sessions = TrainingSession::query()
            ->whereIn('training_program_id', $seated->pluck('training_program_id'))
            ->ofLivePrograms()
            ->whereBetween('session_date', [$today, now()->addDays(self::AHEAD_DAYS)->toDateString()])
            ->with('program:id,name,provider')
            ->chronological()
            ->get();

        $certificates = $employee === null ? collect() : TrainingCertificates::register()->where('employee_id', $employee->id)->latest('issued_date')->latest('id')->get();

        return Inertia::render('training/me', [
            'enrollments' => $enrollments->map(fn (TrainingEnrollment $e): array => [
                'id' => $e->id,
                'status' => $e->status,
                'score' => $e->score === null ? null : (float) $e->score,
                'completed_at' => $e->completed_at?->toDateString(),
                'requested_on' => $e->created_at?->toDateString(),
                'decision_note' => $e->status === 'declined' ? $e->decision_note : null,
                'attendance' => TrainingSessions::rate($e->attendances->pluck('status')),
                'can_withdraw' => $e->status === 'requested'
                    || ($e->status === 'enrolled' && $e->program->status() === 'upcoming' && $e->attendances->isEmpty()),
                'certificate_id' => $e->certificate?->id,
                'program' => [
                    'hashid' => $e->program->hashid,
                    'name' => $e->program->name,
                    'provider' => $e->program->provider,
                    'description' => $e->program->description,
                    'start_date' => $e->program->start_date?->toDateString(),
                    'end_date' => $e->program->end_date?->toDateString(),
                    'status' => $e->program->status(),
                ],
            ])->values()->all(),
            'sessions' => $sessions->map(fn (TrainingSession $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'session_date' => $s->session_date->toDateString(),
                'starts_at' => $s->startTime(),
                'ends_at' => $s->endTime(),
                'location' => $s->location,
                'notes' => $s->notes,
                'program' => ['name' => $s->program?->name, 'provider' => $s->program?->provider],
            ])->values()->all(),
            'certificates' => TrainingCertificateResource::listing($certificates, $request),
            'tab' => in_array($request->query('tab'), ['sessions', 'programs', 'certificates'], true) ? $request->query('tab') : null,
            'has_employee' => $employee !== null,
            'nav' => $this->navCounts($request),
        ]);
    }

    /**
     * The programs the person may join or ask to join — open to self-enrolment,
     * not over — with where they stand on each.
     */
    public function catalog(Request $request): Response
    {
        $employee = $this->ownEmployee($request);

        $programs = TrainingProgram::query()
            ->where('enrollment_mode', '!=', 'closed')
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->withCount('sessions')
            ->recentFirst()
            ->get()
            ->filter(fn (TrainingProgram $p): bool => $p->acceptsSelfEnrollment())
            ->values();

        $mine = $employee === null ? collect() : TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereIn('training_program_id', $programs->pluck('id'))
            ->get(['id', 'training_program_id', 'status', 'decision_note'])
            ->keyBy('training_program_id');

        return Inertia::render('training/catalog', [
            'programs' => $programs->map(fn (TrainingProgram $p): array => [
                ...(new TrainingProgramResource($p))->resolve($request),
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
        $employee = $this->ownEmployee($request);

        abort_unless(
            $employee !== null && $session->program !== null && TrainingEnrollment::query()
                ->where('training_program_id', $session->training_program_id)
                ->where('employee_id', $employee->id)
                ->active()
                ->exists(),
            404,
        );

        $slug = Str::slug($session->program->name) ?: 'training';

        return response(EventCalendar::document([], sessions: [$session]), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$slug}-{$session->session_date->toDateString()}.ics\"",
        ]);
    }

    private function assertOwn(Request $request, TrainingEnrollment $enrollment): void
    {
        abort_unless($enrollment->employee_id === $this->ownEmployee($request)?->id, 404);
    }
}
