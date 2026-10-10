<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\CalibrationAdjustmentRequest;
use App\Http\Requests\Performance\CalibrationSessionRequest;
use App\Http\Resources\CalibrationSessionResource;
use App\Http\Resources\EvaluationPeriodResource;
use App\Models\CalibrationSession;
use App\Models\Department;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Performance\AppraisalException;
use App\Support\Performance\CalibrationBoard;
use App\Support\Performance\CalibrationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calibration sessions (ADR 0073): the sessions of a cycle, and one session's
 * room — the appraisals it covers, the spread before and after, and moving a
 * rating with a reason. Viewing needs `performance.view`; opening, editing,
 * moving ratings, completing and cancelling need `performance.manage`. The work
 * is {@see CalibrationWorkflow}.
 */
class CalibrationController extends Controller
{
    use PerformanceResponses;

    public function __construct(private readonly CalibrationWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $periods = EvaluationPeriod::query()->withCount('evaluations')->recentFirst()->get();
        $period = $periods->firstWhere('id', $request->integer('period'))
            ?? $periods->firstWhere('status', 'open')
            ?? $periods->first();

        $sessions = CalibrationSession::query()
            ->where('evaluation_period_id', $period?->id ?? 0)
            ->with(['period:id,name,status', 'facilitator:id,first_name,last_name', 'participants:id,first_name,last_name'])
            ->withCount('adjustments')
            ->orderByRaw("case status when 'open' then 0 when 'completed' then 1 else 2 end")
            ->latest('id')
            ->get();

        return Inertia::render('performance/calibration', [
            'sessions' => CalibrationSessionResource::collection($sessions)->resolve($request),
            'periods' => EvaluationPeriodResource::collection($periods)->resolve($request),
            'currentPeriodId' => $period?->id,
            'departments' => $this->departments(),
            'calibrators' => $this->calibrators(),
            'can' => ['manage' => $request->user()->can('performance.manage')],
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function store(CalibrationSessionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $period = EvaluationPeriod::findOrFail($data['evaluation_period_id']);

        try {
            $session = $this->workflow->open(
                $period,
                $data['name'],
                $data['department_ids'] ?? null,
                $data['scheduled_for'] ?? null,
                $data['notes'] ?? null,
                $this->participants($data['participant_ids'] ?? []),
                $request->user(),
            );
        } catch (AppraisalException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Calibration session opened.']);

        return redirect()->route('performance.calibration.show', $session);
    }

    public function show(Request $request, CalibrationSession $session, CalibrationBoard $board): Response
    {
        $session->load(['period:id,name,status', 'facilitator:id,first_name,last_name', 'participants:id,first_name,last_name'])
            ->loadCount('adjustments');

        return Inertia::render('performance/calibration-session', [
            'session' => (new CalibrationSessionResource($session))->resolve($request),
            'board' => $board->for($session),
            'calibrators' => $this->calibrators(),
            'can' => ['manage' => $request->user()->can('performance.manage')],
            'me' => $request->user()->id,
            'nav' => $this->navCounts($request->user()),
        ]);
    }

    public function update(CalibrationSessionRequest $request, CalibrationSession $session): RedirectResponse
    {
        $data = $request->validated();

        return $this->attempt(fn () => $this->workflow->update(
            $session,
            $data['name'],
            $data['scheduled_for'] ?? null,
            $data['notes'] ?? null,
            $this->participants($data['participant_ids'] ?? []),
            $request->user(),
        ), 'Session updated.');
    }

    public function adjust(CalibrationAdjustmentRequest $request, CalibrationSession $session): RedirectResponse
    {
        $data = $request->validated();
        $evaluation = PerformanceEvaluation::findOrFail($data['evaluation_id']);

        return $this->attempt(
            fn () => $this->workflow->adjust($session, $evaluation, $data['band'], $data['reason'], $request->user()),
            fn ($adjustment): string => "Moved to “{$adjustment->to_label}”.",
        );
    }

    public function complete(Request $request, CalibrationSession $session): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->workflow->complete($session),
            fn (int $released): string => 'Session completed.'.($released > 0
                ? " {$released} ".str('appraisal')->plural($released).' shared with '.($released === 1 ? 'its employee.' : 'their employees.')
                : ''),
        );
    }

    public function cancel(Request $request, CalibrationSession $session): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->workflow->cancel($session),
            fn (int $released): string => 'Session cancelled.'.($released > 0
                ? " {$released} held ".str('appraisal')->plural($released).' shared as they stood.'
                : ''),
        );
    }

    /**
     * Who can take part: everyone here who may view appraisals.
     *
     * @return list<array{id: int, name: string}>
     */
    private function calibrators(): array
    {
        return Notifier::holdersOf('performance.view')
            ->sortBy('first_name')
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->full_name])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function participants(array $ids): Collection
    {
        $allowed = collect($this->calibrators())->pluck('id')->all();

        return User::query()->whereIn('id', array_intersect($ids, $allowed))->get();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function departments(): array
    {
        return Department::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Department $d): array => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }
}
