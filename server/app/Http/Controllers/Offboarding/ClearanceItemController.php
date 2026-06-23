<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\StoreClearanceItemRequest;
use App\Models\ClearanceItem;
use App\Models\OffboardingCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClearanceItemController extends Controller
{
    /**
     * Add an ad-hoc clearance item to a case's checklist.
     */
    public function store(StoreClearanceItemRequest $request, OffboardingCase $case): RedirectResponse
    {
        $case->clearanceItems()->create([
            ...$request->validated(),
            'status' => 'pending',
            'sort_order' => (int) $case->clearanceItems()->max('sort_order') + 1,
        ]);

        return $this->respond('Clearance item added.');
    }

    /**
     * Edit a clearance item's label, owning department and remarks.
     */
    public function update(StoreClearanceItemRequest $request, ClearanceItem $item): RedirectResponse
    {
        $item->update($request->validated());

        return $this->respond('Clearance item updated.');
    }

    /**
     * Set a clearance item's status, stamping the sign-off when it is cleared.
     */
    public function toggle(Request $request, ClearanceItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(ClearanceItem::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $cleared = $validated['status'] === 'cleared';

        $item->update([
            'status' => $validated['status'],
            'remarks' => $request->has('remarks') ? ($validated['remarks'] ?? null) : $item->remarks,
            'cleared_by' => $cleared ? $request->user()->id : null,
            'cleared_at' => $cleared ? now() : null,
        ]);

        $this->touchCaseProgress($item->case);

        return $this->respond('Clearance updated.');
    }

    /**
     * Remove a clearance item from the checklist.
     */
    public function destroy(ClearanceItem $item): RedirectResponse
    {
        $item->delete();

        return $this->respond('Clearance item removed.');
    }

    /**
     * Nudge a case from "initiated" into "clearance" once any sign-off activity
     * has happened, so the board reflects progress without a manual status change.
     */
    private function touchCaseProgress(OffboardingCase $case): void
    {
        if ($case->status !== 'initiated') {
            return;
        }

        $hasActivity = $case->clearanceItems()
            ->whereIn('status', ['cleared', 'flagged'])
            ->exists();

        if ($hasActivity) {
            $case->update(['status' => 'clearance']);
        }
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
