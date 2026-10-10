<?php

namespace App\Http\Controllers\Recognition;

use App\Http\Controllers\Controller;
use App\Models\AwardNomination;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Queries\RecognitionFeed;
use App\Support\Recognition\PointsLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recognition for everybody (ADR 0071), gated by `awards.participate`: the wall
 * of kudos and awards, the person's own nominations, and their points and
 * rewards. The writes are in the kudos, nomination and redemption controllers.
 */
class RecognitionController extends Controller
{
    public function index(Request $request): Response
    {
        $employee = $request->user()->employee()->first();

        return Inertia::render('awards/wall', [
            'feed' => RecognitionFeed::wall($request->user()->can('awards.manage')),
            'me' => RecognitionFeed::me($employee),
            'colleagues' => RecognitionFeed::colleagues($employee),
            'types' => RecognitionFeed::nominatableTypes(),
            'can' => ['manage' => $request->user()->can('awards.manage')],
        ]);
    }

    public function nominations(Request $request): Response
    {
        $employee = $request->user()->employee()->first();

        $nominations = AwardNomination::query()
            ->where('nominated_by', $request->user()->id)
            ->with(['employee.position:id,title', 'awardType', 'award', 'reviewer:id,first_name,last_name'])
            ->latest('id')
            ->limit(100)
            ->get();

        return Inertia::render('awards/my-nominations', [
            'nominations' => $nominations->map(fn (AwardNomination $n): array => RecognitionFeed::nomination($n))->all(),
            'me' => RecognitionFeed::me($employee),
            'colleagues' => RecognitionFeed::colleagues($employee),
            'types' => RecognitionFeed::nominatableTypes(),
        ]);
    }

    public function rewards(Request $request, PointsLedger $ledger): Response
    {
        $employee = $request->user()->employee()->first();
        $balance = $employee ? $ledger->balance($employee) : 0;

        return Inertia::render('awards/points', [
            'me' => RecognitionFeed::me($employee),
            'rewards' => Reward::query()->where('is_active', true)->orderBy('cost')->orderBy('name')->get()
                ->map(fn (Reward $reward): array => RecognitionFeed::reward($reward, $balance))->all(),
            'redemptions' => $employee
                ? RewardRedemption::query()->where('employee_id', $employee->id)->with('reward', 'handler:id,first_name,last_name')->latest('id')->limit(50)->get()
                    ->map(fn (RewardRedemption $r): array => RecognitionFeed::redemption($r))->all()
                : [],
            'history' => $employee ? RecognitionFeed::history($ledger->history($employee)) : [],
        ]);
    }
}
