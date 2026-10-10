<?php

namespace App\Http\Controllers\Recognition;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recognition\NominationRequest;
use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Support\Recognition\NominationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Nominate a colleague, or withdraw one's own nomination while it waits
 * (`awards.participate`). Through {@see NominationWorkflow}.
 */
class NominationController extends Controller
{
    use RecognitionResponses;

    public function store(NominationRequest $request, NominationWorkflow $workflow): RedirectResponse
    {
        $nominee = Employee::query()->findOrFail($request->validated('employee_id'));
        $type = AwardType::query()->findOrFail($request->validated('award_type_id'));

        return $this->attempt(
            fn () => $workflow->nominate($nominee, $type, $request->validated('reason'), $request->user()),
            'Nomination sent — HR will review it.',
        );
    }

    public function destroy(Request $request, AwardNomination $nomination, NominationWorkflow $workflow): RedirectResponse
    {
        abort_unless($nomination->nominated_by === $request->user()->id, 404);

        return $this->attempt(fn () => $workflow->withdraw($nomination, $request->user()), 'Nomination withdrawn.');
    }
}
