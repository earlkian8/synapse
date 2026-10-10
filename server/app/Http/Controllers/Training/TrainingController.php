<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingCostResource;
use App\Http\Resources\TrainingEnrollmentResource;
use App\Http\Resources\TrainingProgramResource;
use App\Http\Resources\TrainingSessionResource;
use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\ActivityLogger;
use App\Support\Training\TrainingBudgets;
use App\Support\Training\TrainingInsights;
use App\Support\Training\TrainingSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training & Development — the live view of the organisation's training program: an
 * overview of every program with its schedule, seat usage and completion count,
 * and a program's roster of enrolled employees plus its effectiveness analytics
 * and on-demand AI read. Programs are created in this module (there is no
 * Company-Setup config). Programs are addressed by hashid.
 *
 * A program's page (ADRs 0074, 0075) carries the requests to join that wait,
 * its sessions with the attendance taken at them and each person's rate, and
 * its costs against its budget. Somebody who only takes part
 * (`training.participate`) is sent from the overview to their own training.
 */
class TrainingController extends Controller
{
    use TrainingResponses;

    /**
     * The training overview: KPIs, the requests to join that wait, and a row
     * per program.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()->cannot('training.view')) {
            abort_unless($request->user()->can('training.participate'), 403);

            return redirect()->route('training.me');
        }

        $programs = $this->withCounts(TrainingProgram::query())->recentFirst()->get();
        $archived = $this->withCounts(TrainingProgram::query()->onlyTrashed())->recentFirst()->get();

        $requests = TrainingEnrollment::query()
            ->pending()
            ->whereHas('program')
            ->with([
                'program:id,name,provider,start_date,end_date,capacity',
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
            ])
            ->oldest('id')
            ->get();

        return Inertia::render('training/index', [
            'programs' => TrainingProgramResource::collection($programs)->resolve($request),
            'archived' => TrainingProgramResource::collection($archived)->resolve($request),
            'requests' => TrainingEnrollmentResource::collection($requests)->resolve($request),
            'stats' => $this->stats($programs),
            'can' => $this->permissions($request),
            'nav' => $this->navCounts($request),
        ]);
    }

    /**
     * A single program with its roster and the requests to join, its sessions
     * and attendance, its costs against its budget, the employees still
     * available to enroll, effectiveness analytics and any saved AI read.
     */
    public function show(Request $request, TrainingProgram $trainingProgram, TrainingInsights $insights): Response
    {
        $trainingProgram->loadCount([
            'enrollments' => fn (Builder $query) => $query->onRoster(),
            'enrollments as active_enrollments_count' => fn (Builder $query) => $query->active(),
            'enrollments as completed_enrollments_count' => fn (Builder $query) => $query->where('status', 'completed'),
            'enrollments as pending_enrollments_count' => fn (Builder $query) => $query->pending(),
            'sessions',
        ])->load([
            'enrollments' => fn ($query) => $query->whereIn('status', [...TrainingEnrollment::ROSTER_STATUSES, 'requested'])
                ->orderByRaw("status = 'dropped'")->latest('id'),
            'enrollments.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'enrollments.employee.department:id,name',
            'enrollments.employee.position:id,title',
            'enrollments.certificate:id,training_enrollment_id,certificate_no,expiry_date',
            'costs' => fn ($query) => $query->with('recorder:id,first_name,last_name')->latest('incurred_on')->latest('id'),
        ]);

        [$requests, $roster] = $trainingProgram->enrollments->partition(fn (TrainingEnrollment $e): bool => $e->status === 'requested');
        $trainingProgram->setRelation('enrollments', $roster->values());

        $sessions = $trainingProgram->sessions()->chronological()->with('attendances:id,training_session_id,training_enrollment_id,status')->get();
        $held = $sessions->filter(fn (TrainingSession $s): bool => $s->isHeld());
        $marks = $held->flatMap(fn (TrainingSession $s) => $s->attendances->pluck('status'));

        return Inertia::render('training/show', [
            'program' => (new TrainingProgramResource($trainingProgram))->resolve($request),
            'requests' => TrainingEnrollmentResource::collection($requests->values())->resolve($request),
            'sessions' => TrainingSessionResource::collection($sessions)->resolve($request),
            'attendance' => [
                'by_enrollment' => TrainingSessions::rates($trainingProgram),
                'sessions' => $sessions->count(),
                'held' => $held->count(),
                ...TrainingSessions::rate($marks),
            ],
            'costs' => TrainingCostResource::collection($trainingProgram->costs)->resolve($request),
            'money' => TrainingBudgets::forProgram($trainingProgram),
            'enrollable' => $this->enrollableEmployees($trainingProgram),
            'analytics' => $trainingProgram->analytics(),
            'ai_available' => $insights->enabled(),
            'can' => $this->permissions($request),
            'nav' => $this->navCounts($request),
        ]);
    }

    /**
     * Generate (and persist) the LLM effectiveness read for one program. Mirrors
     * the performance / recruitment insights endpoints: on-demand, cached on the
     * model, never a thrown error — failures resolve to an "unavailable" payload.
     */
    public function insights(TrainingProgram $trainingProgram, TrainingInsights $insights): JsonResponse
    {
        $trainingProgram->load([
            'enrollments.employee:id,first_name,middle_name,last_name,suffix',
        ]);

        $result = $insights->generate(
            $trainingProgram,
            $trainingProgram->analytics(),
            $this->rosterDigest($trainingProgram),
        );

        if ($result['available'] ?? false) {
            $trainingProgram->ai_insights = $result;
            $trainingProgram->save();

            ActivityLogger::log(
                event: 'updated',
                description: "Generated AI training insights for \"{$trainingProgram->name}\"",
                subject: $trainingProgram,
                logName: 'training',
                subjectLabel: $trainingProgram->name,
            );
        }

        return response()->json(['insights' => $result]);
    }

    /**
     * Attach the roster aggregates the table reads: everyone on the roster, the
     * seats taken, completions, requests waiting, and sessions scheduled.
     *
     * @param  Builder<TrainingProgram>  $query
     * @return Builder<TrainingProgram>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->withCount(['enrollments' => fn (Builder $q) => $q->onRoster()])
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->withCount(['enrollments as completed_enrollments_count' => fn (Builder $q) => $q->where('status', 'completed')])
            ->withCount(['enrollments as pending_enrollments_count' => fn (Builder $q) => $q->pending()])
            ->withCount('sessions');
    }

    /**
     * Active employees not yet on this program's roster, for the enroll picker —
     * each with their department so the picker can group / filter by team.
     * Someone who asked to join (or was declined) can still be enrolled.
     *
     * @return list<array{id: int, full_name: string, employee_no: string, department: string|null}>
     */
    private function enrollableEmployees(TrainingProgram $program): array
    {
        $enrolled = $program->enrollments()->onRoster()->pluck('employee_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $enrolled)
            ->with('department:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'department_id'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department?->name,
            ])
            ->all();
    }

    /**
     * A flat participant list (name / status / score) for the LLM digest.
     *
     * @return list<array{name: string, status: string, score: float|null}>
     */
    private function rosterDigest(TrainingProgram $program): array
    {
        return $program->enrollments
            ->filter(fn (TrainingEnrollment $enrollment): bool => $enrollment->isOnRoster())
            ->map(fn ($enrollment): array => [
                'name' => $enrollment->employee?->full_name ?? 'Unknown',
                'status' => $enrollment->status,
                'score' => $enrollment->score === null ? null : (float) $enrollment->score,
            ])
            ->values()
            ->all();
    }

    /**
     * Headline training metrics, derived from the program collection.
     *
     * @param  Collection<int, TrainingProgram>  $programs
     * @return array<string, int>
     */
    private function stats($programs): array
    {
        return [
            'programs' => $programs->count(),
            'requests' => (int) $programs->sum('pending_enrollments_count'),
            'ongoing' => $programs->filter(fn (TrainingProgram $p): bool => $p->status() === 'ongoing')->count(),
            'upcoming' => $programs->filter(fn (TrainingProgram $p): bool => $p->status() === 'upcoming')->count(),
            'enrolled' => (int) $programs->sum('active_enrollments_count'),
            'completed' => (int) $programs->sum('completed_enrollments_count'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('training.manage')];
    }
}
