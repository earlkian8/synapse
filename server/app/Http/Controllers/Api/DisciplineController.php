<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Discipline\ExplanationRequest;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Queries\DisciplineScreens;
use App\Support\Discipline\DisciplineException;
use App\Support\Discipline\DisciplineWorkflow;
use App\Support\Hashid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My notices in the app (ADR 0105), `discipline.respond`: the cases served on
 * oneself, what they say, acknowledging them and answering in writing — the
 * same {@see DisciplineScreens} and {@see DisciplineWorkflow} the web uses. A
 * case is addressed by its hashid; one that isn't the signer's, or not served
 * yet, is a 404.
 */
class DisciplineController extends Controller
{
    public function __construct(
        private readonly DisciplineWorkflow $workflow,
        private readonly DisciplineScreens $screens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json(['data' => $this->screens->mine($employee)]);
    }

    public function show(Request $request, string $case): JsonResponse
    {
        return response()->json(['data' => $this->screens->mineOne($this->own($request, $case))['case']]);
    }

    public function acknowledge(Request $request, string $case): JsonResponse
    {
        $found = $this->own($request, $case);

        try {
            $this->workflow->acknowledge($found, $request->input('notice') === 'decision' ? 'decision' : 'notice');
        } catch (DisciplineException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->screens->mineOne($found->refresh())['case']]);
    }

    public function explain(ExplanationRequest $request, string $case): JsonResponse
    {
        $found = $this->own($request, $case);

        try {
            $this->workflow->explain($found, $request->validated('explanation'), $request->boolean('hearing'), $request->user());
        } catch (DisciplineException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->screens->mineOne($found->refresh())['case']]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }

    private function own(Request $request, string $hashid): DisciplinaryCase
    {
        $employee = $this->employee($request);
        $id = Hashid::decode($hashid);

        $case = $id !== null
            ? DisciplinaryCase::query()->whereKey($id)->where('employee_id', $employee->id)->served()->first()
            : null;

        abort_if($case === null, 404);

        return $case;
    }
}
