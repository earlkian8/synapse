<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Recognition\RecognitionResponses;
use App\Http\Requests\Recognition\PointAdjustmentRequest;
use App\Http\Requests\Recognition\RecognitionSettingsRequest;
use App\Http\Requests\Recognition\ReviewNominationRequest;
use App\Http\Requests\Recognition\RewardRequest;
use App\Models\AwardNomination;
use App\Models\Employee;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Queries\RecognitionFeed;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RewardWorkflow;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Awards → Rewards (ADR 0071), HR's desk (`awards.manage`): the catalogue
 * points are spent on, the requests to fulfil or decline, corrections to a
 * balance, and the kudos settings. Requests and adjustments go through
 * {@see RewardWorkflow} and {@see PointsLedger}.
 */
class RewardController extends Controller
{
    use RecognitionResponses;

    public function index(Request $request): Response
    {
        $organization = app(Tenancy::class)->organization();

        $redemptions = RewardRedemption::query()
            ->where(fn ($query) => $query->pending()->orWhere('updated_at', '>=', now()->subDays(90)))
            ->with(['reward', 'employee.position:id,title', 'handler:id,first_name,last_name'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->limit(200)
            ->get();

        $balances = PointTransaction::query()
            ->selectRaw('employee_id, sum(amount) as balance')
            ->groupBy('employee_id')
            ->orderByDesc('balance')
            ->limit(10)
            ->get();
        $people = Employee::query()->whereIn('id', $balances->pluck('employee_id'))->with('position:id,title')->get()->keyBy('id');

        return Inertia::render('awards/rewards', [
            'rewards' => Reward::withTrashed()
                ->withCount(['redemptions as redeemed' => fn ($query) => $query->whereIn('status', ['pending', 'fulfilled'])])
                ->orderByRaw('deleted_at is not null')
                ->orderBy('cost')
                ->get()
                ->map(fn (Reward $reward): array => [
                    ...RecognitionFeed::reward($reward),
                    'redeemed' => (int) $reward->redeemed,
                ])->all(),
            'redemptions' => $redemptions->map(fn (RewardRedemption $r): array => [
                ...RecognitionFeed::redemption($r),
                'is_mine' => $r->employee?->user_id === $request->user()->id,
            ])->all(),
            'counts' => ['pending' => RewardRedemption::query()->pending()->count()],
            'pending_nominations' => AwardNomination::query()->pending()->count(),
            'balances' => $balances->map(fn ($row): array => [
                'employee' => RecognitionFeed::person($people[$row->employee_id] ?? null),
                'balance' => (int) $row->balance,
            ])->filter(fn (array $row): bool => $row['employee'] !== null)->values()->all(),
            'employees' => RecognitionFeed::colleagues(null),
            'settings' => [
                'kudos_points' => (int) $organization?->kudos_points,
                'kudos_monthly_limit' => (int) $organization?->kudos_monthly_limit,
            ],
        ]);
    }

    public function store(RewardRequest $request): RedirectResponse
    {
        $reward = Reward::create($request->validated());
        $this->log('created', "Added reward \"{$reward->name}\" ({$reward->cost} points)", $reward);

        return $this->toast('Reward added.');
    }

    public function update(RewardRequest $request, Reward $reward): RedirectResponse
    {
        $reward->update($request->validated());
        $this->log('updated', "Updated reward \"{$reward->name}\"", $reward);

        return $this->toast('Reward updated.');
    }

    /**
     * Archive a reward: no longer offered; its requests stay.
     */
    public function destroy(Reward $reward): RedirectResponse
    {
        $reward->delete();
        $this->log('archived', "Archived reward \"{$reward->name}\"", $reward);

        return $this->toast('Reward archived.');
    }

    public function restore(string $reward): RedirectResponse
    {
        $id = Hashid::decode($reward);
        abort_if($id === null, 404);

        $model = Reward::onlyTrashed()->findOrFail($id);
        $model->restore();
        $this->log('restored', "Restored reward \"{$model->name}\"", $model);

        return $this->toast('Reward restored.');
    }

    public function fulfil(ReviewNominationRequest $request, RewardRedemption $redemption, RewardWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(fn () => $workflow->fulfil($redemption, $request->user(), $request->validated('note')), 'Marked as handed over.');
    }

    public function decline(ReviewNominationRequest $request, RewardRedemption $redemption, RewardWorkflow $workflow): RedirectResponse
    {
        return $this->attempt(fn () => $workflow->decline($redemption, $request->user(), $request->validated('note')), 'Declined — the points went back.');
    }

    public function adjust(PointAdjustmentRequest $request, PointsLedger $ledger): RedirectResponse
    {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));

        return $this->attempt(
            fn () => $ledger->adjust($employee, (int) $request->validated('amount'), $request->validated('note'), $request->user()),
            'Points adjusted.',
        );
    }

    public function settings(RecognitionSettingsRequest $request): RedirectResponse
    {
        $organization = app(Tenancy::class)->organization();
        $organization->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Kudos now carry {$organization->kudos_points} points, up to {$organization->kudos_monthly_limit} a month each",
            subject: $organization,
            logName: 'awards',
            subjectLabel: $organization->name,
        );

        return $this->toast('Kudos settings saved.');
    }

    private function log(string $event, string $description, Reward $reward): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $reward,
            logName: 'awards',
            subjectLabel: $reward->name,
        );
    }
}
