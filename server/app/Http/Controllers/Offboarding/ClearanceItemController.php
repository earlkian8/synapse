<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\StoreClearanceItemRequest;
use App\Models\ClearanceItem;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Support\ActivityLogger;
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
     * Append every item of a clearance template to a case's checklist, skipping
     * items already on it (matched by label, case-insensitively) — the fast way to
     * build out a checklist without adding sign-offs one by one.
     */
    public function applyProgram(Request $request, OffboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'offboarding_program_id' => ['required', 'integer', Rule::exists('offboarding_programs', 'id')],
        ]);

        $program = OffboardingProgram::with('items')->findOrFail($validated['offboarding_program_id']);
        $case->loadMissing('employee:id,department_id');

        $existing = $case->clearanceItems()
            ->pluck('item')
            ->map(fn (string $item): string => mb_strtolower(trim($item)))
            ->all();

        $sortOrder = (int) $case->clearanceItems()->max('sort_order');
        $added = 0;

        foreach ($program->items as $blueprint) {
            if (in_array(mb_strtolower(trim($blueprint->item)), $existing, true)) {
                continue;
            }

            $case->clearanceItems()->create([
                'item' => $blueprint->item,
                'department_id' => $blueprint->use_employee_department
                    ? $case->employee?->department_id
                    : $blueprint->department_id,
                'status' => 'pending',
                'sort_order' => ++$sortOrder,
            ]);

            $added++;
        }

        if ($added === 0) {
            return $this->respond('Every item in that template is already on the checklist.', 'warning');
        }

        ActivityLogger::log(
            event: 'created',
            description: "Applied clearance template \"{$program->name}\" ({$added} ".str('item')->plural($added).' added)',
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $case->employee?->full_name,
        );

        return $this->respond($added === 1 ? '1 item added from the template.' : "{$added} items added from the template.");
    }

    /**
     * Sign off every pending item in one go — case-wide, for one department's
     * group, or for the unassigned group. Flagged items are deliberately left
     * untouched; they represent real outstanding issues.
     */
    public function bulkClear(Request $request, OffboardingCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['all', 'department', 'unassigned'])],
            'department_id' => ['required_if:scope,department', 'nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        $pending = $case->clearanceItems()
            ->where('status', 'pending')
            ->when($validated['scope'] === 'department', fn ($query) => $query->where('department_id', $validated['department_id']))
            ->when($validated['scope'] === 'unassigned', fn ($query) => $query->whereNull('department_id'));

        $cleared = $pending->update([
            'status' => 'cleared',
            'cleared_by' => $request->user()->id,
            'cleared_at' => now(),
        ]);

        if ($cleared === 0) {
            return $this->respond('Nothing pending to clear there.', 'warning');
        }

        $this->touchCaseProgress($case);

        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'updated',
            description: "Cleared {$cleared} pending clearance ".str('item')->plural($cleared).' in bulk',
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $case->employee?->full_name,
        );

        return $this->respond($cleared === 1 ? '1 item cleared.' : "{$cleared} items cleared.");
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
