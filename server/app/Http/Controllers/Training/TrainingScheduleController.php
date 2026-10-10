<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The training calendar (ADR 0074): every session of every live program in one
 * month, on the organisation's clock, to see what runs when — and what clashes.
 * `?month=YYYY-MM` picks the month; it defaults to this one.
 */
class TrainingScheduleController extends Controller
{
    use TrainingResponses;

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::parse(OrganizationClock::today());
        $month = $this->month((string) $request->query('month', ''), $today);

        $sessions = TrainingSession::query()
            ->ofLivePrograms()
            ->whereBetween('session_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->with('program:id,name,provider')
            ->withCount('attendances')
            ->chronological()
            ->get();

        return Inertia::render('training/schedule', [
            'month' => $month->format('Y-m'),
            'today' => $today->toDateString(),
            'sessions' => $sessions->map(fn (TrainingSession $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'session_date' => $s->session_date->toDateString(),
                'starts_at' => $s->startTime(),
                'ends_at' => $s->endTime(),
                'location' => $s->location,
                'is_held' => $s->isHeld(),
                'marked' => (int) $s->attendances_count,
                'program' => ['id' => $s->program?->id, 'hashid' => $s->program?->hashid, 'name' => $s->program?->name, 'provider' => $s->program?->provider],
            ])->values()->all(),
            // Programs running in the month with no session yet, so a gap shows.
            'unscheduled' => TrainingProgram::query()
                ->whereDoesntHave('sessions')
                ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $month->endOfMonth()->toDateString()))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $month->startOfMonth()->toDateString()))
                ->whereNotNull('start_date')
                ->orderBy('start_date')
                ->get(['id', 'name', 'start_date', 'end_date'])
                ->map(fn (TrainingProgram $p): array => ['hashid' => $p->hashid, 'name' => $p->name, 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString()])
                ->all(),
            'can' => ['manage' => $request->user()->can('training.manage')],
            'nav' => $this->navCounts($request),
        ]);
    }

    private function month(string $value, CarbonImmutable $today): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1);
        }

        return $today->startOfMonth();
    }
}
