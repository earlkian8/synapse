<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\LeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveTypeController extends Controller
{
    /**
     * Display the leave-type catalogue (Company Setup).
     */
    public function index(Request $request): Response
    {
        return Inertia::render('setup/leave-types', [
            'types' => LeaveTypeResource::collection($this->listing()->get())->resolve($request),
            'archived' => LeaveTypeResource::collection(
                $this->listing()->onlyTrashed()->get()
            )->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.leave-types.manage')],
        ]);
    }

    /**
     * Store a new leave type.
     */
    public function store(LeaveTypeRequest $request): RedirectResponse
    {
        $type = LeaveType::create($request->validated());

        ActivityLogger::log(
            event: 'created',
            description: "Created leave type \"{$type->name}\"",
            subject: $type,
            logName: 'company-setup',
            subjectLabel: $type->name,
        );

        return $this->respond('Leave type created.');
    }

    /**
     * Update the given leave type.
     */
    public function update(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $leaveType->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated leave type \"{$leaveType->name}\"",
            subject: $leaveType,
            logName: 'company-setup',
            subjectLabel: $leaveType->name,
        );

        return $this->respond('Leave type updated.');
    }

    /**
     * Archive (soft-delete) a leave type. Filed requests keep their type.
     */
    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        $name = $leaveType->name;
        $leaveType->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived leave type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Leave type archived.');
    }

    /**
     * Restore an archived leave type.
     */
    public function restore(string $leaveType): RedirectResponse
    {
        $model = $this->findTrashed($leaveType);

        // The per-tenant unique index ignores archived rows, so the code may have
        // been reused while this one was archived — refuse rather than hit a DB error.
        $clash = LeaveType::where('code', $model->code)->whereKeyNot($model->id)->exists();

        if ($clash) {
            return $this->respond("Another leave type already uses the code \"{$model->code}\".", 'warning');
        }

        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored leave type \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Leave type restored.');
    }

    /**
     * Permanently delete an archived leave type — only when nothing references it.
     */
    public function forceDelete(string $leaveType): RedirectResponse
    {
        $model = $this->findTrashed($leaveType);

        if ($model->requests()->exists()) {
            return $this->respond('This leave type has leave requests and cannot be permanently deleted.', 'warning');
        }

        $name = $model->name;
        $model->balances()->delete();
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted leave type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Leave type permanently deleted.');
    }

    /**
     * The base listing query, shared by the active and archived sets.
     *
     * @return Builder<LeaveType>
     */
    private function listing(): Builder
    {
        return LeaveType::query()
            ->withCount('requests')
            ->orderByDesc('is_active')
            ->orderBy('name');
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
