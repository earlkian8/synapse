<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\DeductionTypeRequest;
use App\Models\DeductionType;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Company Setup → deduction types (the deduction kinds payslip lines can be typed
 * by). The UI edits a flat percentage rate + optional cap; the stored
 * `computation` config is assembled from it. Addressed by hashid.
 */
class DeductionTypeController extends Controller
{
    public function store(DeductionTypeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $type = DeductionType::create([
            'name' => $data['name'],
            'kind' => $data['kind'],
            'is_mandatory' => $data['is_mandatory'] ?? false,
            'computation' => $this->computation($data, null),
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Created deduction type \"{$type->name}\"",
            subject: $type,
            logName: 'company-setup',
            subjectLabel: $type->name,
        );

        return $this->respond('Deduction type created.');
    }

    public function update(DeductionTypeRequest $request, DeductionType $deductionType): RedirectResponse
    {
        $data = $request->validated();

        $deductionType->update([
            'name' => $data['name'],
            'kind' => $data['kind'],
            'is_mandatory' => $data['is_mandatory'] ?? false,
            'computation' => $this->computation($data, $deductionType),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated deduction type \"{$deductionType->name}\"",
            subject: $deductionType,
            logName: 'company-setup',
            subjectLabel: $deductionType->name,
        );

        return $this->respond('Deduction type updated.');
    }

    public function destroy(DeductionType $deductionType): RedirectResponse
    {
        $name = $deductionType->name;
        $deductionType->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived deduction type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Deduction type archived.');
    }

    public function restore(string $deductionType): RedirectResponse
    {
        $model = $this->findTrashed($deductionType);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored deduction type \"{$model->name}\"",
            subject: $model,
            logName: 'company-setup',
            subjectLabel: $model->name,
        );

        return $this->respond('Deduction type restored.');
    }

    public function forceDelete(string $deductionType): RedirectResponse
    {
        $model = $this->findTrashed($deductionType);
        $name = $model->name;
        // Historical payslip lines keep their label; the FK is nulled on delete.
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted deduction type \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Deduction type permanently deleted.');
    }

    /**
     * Assemble the computation config from the form's flat rate + cap. When no
     * rate is given the existing config is kept — so a seeded bracket deduction
     * (e.g. withholding tax) is not clobbered by an empty edit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function computation(array $data, ?DeductionType $existing): ?array
    {
        $rate = $data['rate'] ?? null;

        if ($rate === null || $rate === '') {
            return $existing?->computation;
        }

        $computation = ['type' => 'rate', 'base' => 'taxable', 'rate' => round((float) $rate / 100, 5)];

        if (! empty($data['cap'])) {
            $computation['max'] = (float) $data['cap'];
        }

        return $computation;
    }

    private function findTrashed(string $hashid): DeductionType
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return DeductionType::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
