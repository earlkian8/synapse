<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\ApplyClearanceTemplateRequest;
use App\Http\Requests\Offboarding\BulkClearClearanceRequest;
use App\Http\Requests\Offboarding\ClearanceStatusRequest;
use App\Http\Requests\Offboarding\StoreClearanceItemRequest;
use App\Models\ClearanceItem;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\OffboardingWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A case's clearance checklist: add, edit, sign off, flag and remove items,
 * apply a template, or clear everything pending at once. The rules — and the
 * audit trail, which these writes did not leave before — live in
 * {@see OffboardingWorkflow}, the path the assistant takes too. Thin (route gate
 * `offboarding.manage`).
 */
class ClearanceItemController extends Controller
{
    public function __construct(private readonly OffboardingWorkflow $workflow) {}

    /**
     * Add an ad-hoc clearance item to a case's checklist.
     */
    public function store(StoreClearanceItemRequest $request, OffboardingCase $case): RedirectResponse
    {
        $this->workflow->addItem($case, $request->validated());

        return $this->respond('Clearance item added.');
    }

    /**
     * Append every item of a clearance template to a case's checklist, skipping
     * items already on it (matched by label, case-insensitively).
     */
    public function applyProgram(ApplyClearanceTemplateRequest $request, OffboardingCase $case): RedirectResponse
    {
        $program = OffboardingProgram::findOrFail($request->validated('offboarding_program_id'));

        try {
            $added = $this->workflow->applyTemplate($case, $program);
        } catch (OffboardingException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond($added === 1 ? '1 item added from the template.' : "{$added} items added from the template.");
    }

    /**
     * Sign off every pending item in one go — case-wide, for one department's
     * group, or for the unassigned group. Flagged items are left untouched.
     */
    public function bulkClear(BulkClearClearanceRequest $request, OffboardingCase $case): RedirectResponse
    {
        try {
            $cleared = $this->workflow->clearPending(
                $case,
                $request->validated('scope'),
                $request->validated('department_id') !== null ? (int) $request->validated('department_id') : null,
                $request->user(),
            );
        } catch (OffboardingException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond($cleared === 1 ? '1 item cleared.' : "{$cleared} items cleared.");
    }

    /**
     * Edit a clearance item's label, owning department and remarks.
     */
    public function update(StoreClearanceItemRequest $request, ClearanceItem $item): RedirectResponse
    {
        $this->workflow->updateItem($item, $request->validated());

        return $this->respond('Clearance item updated.');
    }

    /**
     * Set a clearance item's status, stamping the sign-off when it is cleared.
     */
    public function toggle(ClearanceStatusRequest $request, ClearanceItem $item): RedirectResponse
    {
        $this->workflow->setItemStatus(
            $item,
            $request->validated('status'),
            $request->user(),
            $request->validated('remarks'),
            setRemarks: $request->has('remarks'),
        );

        return $this->respond('Clearance updated.');
    }

    /**
     * Remove a clearance item from the checklist.
     */
    public function destroy(ClearanceItem $item): RedirectResponse
    {
        $this->workflow->removeItem($item);

        return $this->respond('Clearance item removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
