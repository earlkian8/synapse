<?php

namespace App\Http\Controllers\Recognition;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recognition\RedeemRequest;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Support\Recognition\RewardWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Spend points on a reward, or cancel the request while it waits
 * (`awards.participate`). Through {@see RewardWorkflow}.
 */
class RedemptionController extends Controller
{
    use RecognitionResponses;

    public function store(RedeemRequest $request, Reward $reward, RewardWorkflow $workflow): RedirectResponse
    {
        $employee = $this->employee($request);

        return $this->attempt(
            fn () => $workflow->redeem($employee, $reward, $request->validated('note')),
            "{$reward->name} requested — HR will be in touch.",
        );
    }

    public function cancel(Request $request, RewardRedemption $redemption, RewardWorkflow $workflow): RedirectResponse
    {
        $employee = $this->employee($request);

        abort_unless($redemption->employee_id === $employee->id, 404);

        return $this->attempt(fn () => $workflow->cancel($redemption, $employee), 'Request cancelled — your points are back.');
    }
}
