<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\LeaveTypeRequest;
use App\Models\LeaveType;
use App\Queries\Setup\LeaveTypesScreen;
use App\Support\Hashid;
use App\Support\Setup\LeaveTypeException;
use App\Support\Setup\LeaveTypeWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Leave Types: the kinds of leave the organisation grants, with
 * their entitlement and policy. Addressed by hashid; restore / force-delete take
 * it as a string. Thin controller: every write is {@see LeaveTypeWorkflow},
 * which the assistant uses too.
 */
class LeaveTypeController extends Controller
{
    public function __construct(private readonly LeaveTypeWorkflow $workflow) {}

    /**
     * Display the leave-type catalogue (Company Setup).
     */
    public function index(Request $request, LeaveTypesScreen $screen): Response
    {
        return Inertia::render('setup/leave-types', $screen->toArray($request));
    }

    /**
     * Store a new leave type.
     */
    public function store(LeaveTypeRequest $request): RedirectResponse
    {
        $this->workflow->create($request->validated());

        return $this->respond('Leave type created.');
    }

    /**
     * Update the given leave type.
     */
    public function update(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $this->workflow->update($leaveType, $request->validated());

        return $this->respond('Leave type updated.');
    }

    /**
     * Archive (soft-delete) a leave type. Filed requests keep their type.
     */
    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        $this->workflow->archive($leaveType);

        return $this->respond('Leave type archived.');
    }

    /**
     * Restore an archived leave type — refused when its code was reused.
     */
    public function restore(string $leaveType): RedirectResponse
    {
        try {
            $this->workflow->restore($this->findTrashed($leaveType));
        } catch (LeaveTypeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Leave type restored.');
    }

    /**
     * Permanently delete an archived leave type — only when nothing references it.
     */
    public function forceDelete(string $leaveType): RedirectResponse
    {
        try {
            $this->workflow->forceDelete($this->findTrashed($leaveType));
        } catch (LeaveTypeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Leave type permanently deleted.');
    }

    /**
     * Resolve an archived leave type from its hashid, or 404.
     */
    private function findTrashed(string $code): LeaveType
    {
        $id = Hashid::decode($code);

        abort_if($id === null, 404);

        return LeaveType::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
