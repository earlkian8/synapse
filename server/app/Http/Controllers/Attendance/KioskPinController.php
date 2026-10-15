<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\IssueKioskPinsRequest;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Support\Attendance\KioskWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Who clocks in at a kiosk (ADR 0092): the PINs HR issues and withdraws, on
 * Attendance → Kiosks beside the kiosks they work at, through
 * {@see KioskWorkflow}.
 *
 * An issued PIN is a secret shown once — answered as JSON for the screen to
 * show and print, never kept in the page's props. It works once, for its holder
 * to choose their own at a kiosk.
 */
class KioskPinController extends Controller
{
    public function __construct(private readonly KioskWorkflow $workflow) {}

    public function store(IssueKioskPinsRequest $request): JsonResponse
    {
        $employees = Employee::query()
            ->with('kioskPin')
            ->whereKey($request->validated('employee_ids'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json(['issued' => $this->workflow->issuePins($employees, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Withdraw somebody's PIN: they can no longer clock in at any kiosk.
     */
    public function destroy(EmployeeKioskPin $employeeKioskPin): RedirectResponse
    {
        $name = $employeeKioskPin->employee?->first_name ?? 'They';

        $this->workflow->withdrawPin($employeeKioskPin);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} can no longer clock in at a kiosk."]);

        return back();
    }
}
