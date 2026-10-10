<?php

namespace App\Http\Controllers\Recognition;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recognition\KudosRequest;
use App\Models\Employee;
use App\Models\Kudos;
use App\Support\Recognition\KudosWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Send kudos (`awards.participate`), or take them down from the wall
 * (`awards.manage`). Through {@see KudosWorkflow}, as the app and the
 * assistant do.
 */
class KudosController extends Controller
{
    use RecognitionResponses;

    public function store(KudosRequest $request, KudosWorkflow $workflow): RedirectResponse
    {
        $from = $this->employee($request);
        $to = Employee::query()->findOrFail($request->validated('to_employee_id'));

        return $this->attempt(
            fn (): Kudos => $workflow->send($from, $to, $request->validated('message')),
            fn (Kudos $kudos): string => 'Kudos sent to '.Str::before($to->full_name, ' ').($kudos->points > 0 ? " — +{$kudos->points} points." : '.'),
        );
    }

    public function destroy(Request $request, Kudos $kudos, KudosWorkflow $workflow): RedirectResponse
    {
        $workflow->remove($kudos, $request->user());

        return $this->toast('Kudos taken down.');
    }
}
