<?php

namespace App\Http\Controllers\CheckIns;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIns\CheckInSettingsRequest;
use App\Support\ActivityLogger;
use App\Support\CheckIns\CheckInPeriod;
use App\Support\CheckIns\CheckInQuestions;
use App\Support\CheckIns\CheckInResults;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team results (ADR 0102), `check-ins.view`: how the company and each department
 * felt in a month, and the last year's trend — for groups of five or more only
 * ({@see CheckInResults}). Turning check-ins on or off needs `check-ins.manage`.
 */
class CheckInResultsController extends Controller
{
    public function index(Request $request, CheckInResults $results): Response
    {
        $current = CheckInPeriod::current();
        $period = CheckInPeriod::parse($request->query('period'));
        $period = $period !== null && $period->lte($current) ? $period : $current;

        return Inertia::render('check-ins/results', [
            'results' => $results->period($period),
            'trend' => $results->trend($current),
            'periods' => array_map(fn (CarbonImmutable $p): array => [
                'value' => $p->toDateString(),
                'label' => CheckInPeriod::label($p),
            ], CheckInPeriod::recent(CheckInResults::TREND_MONTHS)),
            'questions' => CheckInQuestions::all(),
            'min_group' => CheckInResults::MIN_GROUP,
            'enabled' => (bool) app(Tenancy::class)->organization()?->check_ins_enabled,
            'can' => ['manage' => $request->user()->can('check-ins.manage')],
        ]);
    }

    public function settings(CheckInSettingsRequest $request): RedirectResponse
    {
        $organization = app(Tenancy::class)->organization();
        $organization->update($request->validated());
        $on = $organization->check_ins_enabled;

        ActivityLogger::log(
            event: 'updated',
            description: $on ? 'Turned monthly check-ins on' : 'Turned monthly check-ins off',
            subject: $organization,
            logName: 'check-ins',
            subjectLabel: $organization->name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $on ? 'Check-ins turned on.' : 'Check-ins turned off.']);

        return back();
    }
}
