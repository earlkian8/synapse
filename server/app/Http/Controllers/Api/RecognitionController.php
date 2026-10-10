<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recognition\KudosRequest;
use App\Http\Requests\Recognition\NominationRequest;
use App\Http\Requests\Recognition\RedeemRequest;
use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Queries\RecognitionFeed;
use App\Support\Recognition\KudosWorkflow;
use App\Support\Recognition\NominationWorkflow;
use App\Support\Recognition\PointsLedger;
use App\Support\Recognition\RecognitionException;
use App\Support\Recognition\RewardWorkflow;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Recognition in the mobile app (ADR 0071), `awards.participate`: the wall,
 * colleagues to recognise, kudos, nominations, points and rewards. Self-scoped
 * — another person's nomination or request is not found — and through the same
 * workflows as the web. A refusal is a 422: on its field when it has one, as
 * `message` otherwise.
 */
class RecognitionController extends Controller
{
    public function __construct(private readonly PointsLedger $ledger) {}

    public function wall(Request $request): JsonResponse
    {
        return response()->json([
            'data' => RecognitionFeed::wall(),
            'me' => RecognitionFeed::me($this->employee($request)),
        ]);
    }

    public function colleagues(Request $request): JsonResponse
    {
        $search = $request->string('search')->limit(80)->toString();

        return response()->json(['data' => RecognitionFeed::colleagues($this->employee($request), $search, 50)]);
    }

    public function kudos(KudosRequest $request, KudosWorkflow $workflow): JsonResponse
    {
        $from = $this->employee($request);
        $to = Employee::query()->findOrFail($request->validated('to_employee_id'));

        return $this->attempt(function () use ($workflow, $from, $to, $request): JsonResponse {
            $kudos = $workflow->send($from, $to, $request->validated('message'), ' via the app');

            return response()->json([
                'data' => ['id' => $kudos->id, 'points' => $kudos->points],
                'me' => RecognitionFeed::me($from),
            ], 201);
        });
    }

    public function nominations(Request $request): JsonResponse
    {
        $nominations = AwardNomination::query()
            ->where('nominated_by', $request->user()->id)
            ->with(['employee.position:id,title', 'awardType', 'award', 'reviewer:id,first_name,last_name'])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $nominations->map(fn (AwardNomination $n): array => RecognitionFeed::nomination($n))->all()]);
    }

    public function nominationTypes(): JsonResponse
    {
        return response()->json(['data' => RecognitionFeed::nominatableTypes()]);
    }

    public function nominate(NominationRequest $request, NominationWorkflow $workflow): JsonResponse
    {
        $this->employee($request);
        $nominee = Employee::query()->findOrFail($request->validated('employee_id'));
        $type = AwardType::query()->findOrFail($request->validated('award_type_id'));

        return $this->attempt(fn (): JsonResponse => response()->json([
            'data' => RecognitionFeed::nomination(
                $workflow->nominate($nominee, $type, $request->validated('reason'), $request->user(), ' via the app')
                    ->load(['employee.position:id,title', 'awardType']),
            ),
        ], 201));
    }

    public function withdraw(Request $request, AwardNomination $nomination, NominationWorkflow $workflow): JsonResponse
    {
        abort_unless($nomination->nominated_by === $request->user()->id, 404);

        return $this->attempt(function () use ($workflow, $nomination, $request): JsonResponse {
            $workflow->withdraw($nomination, $request->user(), ' via the app');

            return response()->json(['data' => RecognitionFeed::nomination($nomination->refresh()->load(['employee', 'awardType']))]);
        });
    }

    public function points(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json([
            ...RecognitionFeed::me($employee),
            'history' => RecognitionFeed::history($this->ledger->history($employee)),
        ]);
    }

    public function rewards(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $balance = $this->ledger->balance($employee);

        return response()->json([
            'balance' => $balance,
            'rewards' => Reward::query()->where('is_active', true)->orderBy('cost')->orderBy('name')->get()
                ->map(fn (Reward $reward): array => RecognitionFeed::reward($reward, $balance))->all(),
            'redemptions' => RewardRedemption::query()->where('employee_id', $employee->id)
                ->with('reward', 'handler:id,first_name,last_name')->latest('id')->limit(50)->get()
                ->map(fn (RewardRedemption $r): array => RecognitionFeed::redemption($r))->all(),
        ]);
    }

    public function redeem(RedeemRequest $request, Reward $reward, RewardWorkflow $workflow): JsonResponse
    {
        $employee = $this->employee($request);

        return $this->attempt(fn (): JsonResponse => response()->json([
            'data' => RecognitionFeed::redemption($workflow->redeem($employee, $reward, $request->validated('note'), ' via the app')->load('reward')),
            'balance' => $this->ledger->balance($employee),
        ], 201));
    }

    public function cancel(Request $request, RewardRedemption $redemption, RewardWorkflow $workflow): JsonResponse
    {
        $employee = $this->employee($request);

        abort_unless($redemption->employee_id === $employee->id, 404);

        return $this->attempt(function () use ($workflow, $redemption, $employee): JsonResponse {
            $workflow->cancel($redemption, $employee, ' via the app');

            return response()->json([
                'data' => RecognitionFeed::redemption($redemption->refresh()->load('reward')),
                'balance' => $this->ledger->balance($employee),
            ]);
        });
    }

    /**
     * @param  Closure(): JsonResponse  $call
     */
    private function attempt(Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (RecognitionException $e) {
            if ($e->field !== null) {
                throw ValidationException::withMessages([$e->field => $e->getMessage()]);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Resolve the token user's Employee, or 403 if the account is unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
