<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Requests\Awards\AwardCitationRequest;
use App\Models\AwardType;
use App\Models\Employee;
use App\Support\Awards\AwardCitationWriter;
use App\Support\Awards\AwardNominator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The award nomination board — decision support for recognition. For every
 * active award type, {@see AwardNominator} ranks the employees who deserve it
 * most on the ERP's own signals, and {@see AwardCitationWriter} can draft the
 * citation when one is granted from the board. Manage-gated: it ranks employees
 * against each other.
 */
class AwardNominationController extends Controller
{
    /**
     * The board: every active award type with its ranked shortlist, plus the
     * lookups the give-recognition dialog needs.
     */
    public function index(Request $request, AwardNominator $nominator, AwardCitationWriter $writer): Response
    {
        return Inertia::render('awards/nominations', [
            'board' => $nominator->board(),
            'employees' => $this->activeEmployees(),
            'ai_available' => $writer->enabled(),
            'can' => ['manage' => $request->user()->can('awards.manage')],
        ]);
    }

    /**
     * Draft an AI citation for one employee × award type, grounded in the same
     * signals the board ranked them on. Failures resolve to an "unavailable"
     * payload — never a thrown error.
     */
    public function citation(AwardCitationRequest $request, AwardNominator $nominator, AwardCitationWriter $writer): JsonResponse
    {
        $employee = Employee::query()
            ->with(['department:id,name', 'position:id,title'])
            ->findOrFail($request->validated('employee_id'));

        $type = AwardType::query()->findOrFail($request->validated('award_type_id'));

        $nominee = $nominator->scoreOne($employee, $type);

        $result = $nominee === null
            ? ['available' => false, 'reason' => 'Not enough signals to draft from yet.', 'retryable' => false]
            : $writer->draft($employee, $type, $nominee['components']);

        return response()->json(['citation' => $result]);
    }

    /**
     * Active employees for the give-recognition dialog.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function activeEmployees(): array
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
            ->all();
    }
}
