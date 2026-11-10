<?php

namespace App\Http\Controllers\CheckIns;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIns\CheckInRequest;
use App\Support\CheckIns\CheckInException;
use App\Support\CheckIns\CheckInWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My check-in (ADR 0102), `check-ins.respond`: this month's questions and one's own
 * months. The module's landing page sends HR to the team results and everyone else
 * here. Through {@see CheckInWorkflow}, as the app does.
 */
class CheckInController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->can('check-ins.view') || $user->can('check-ins.respond'), 403);

        return to_route($user->can('check-ins.view') ? 'check-ins.results' : 'check-ins.me');
    }

    public function me(Request $request, CheckInWorkflow $workflow): Response
    {
        return Inertia::render('check-ins/me', [
            'overview' => $workflow->overview($request->user()->employee()->first()),
        ]);
    }

    public function store(CheckInRequest $request, CheckInWorkflow $workflow): RedirectResponse
    {
        $employee = $request->user()->employee()->first();
        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        try {
            $workflow->submit($employee, $request->validated());
        } catch (CheckInException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Check-in saved. Thank you.']);

        return back();
    }
}
