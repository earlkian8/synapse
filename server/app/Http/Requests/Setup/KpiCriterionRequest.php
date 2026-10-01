<?php

namespace App\Http\Requests\Setup;

use App\Models\RatingScale;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a KPI criterion (Company Setup → Performance framework). A
 * criterion is the tenant's **catalogue** entry: what is measured, on which
 * {@see RatingScale}, and the weight a framework starts it at.
 *
 * The scale itself is no longer described here — it is a shared record, so
 * changing "our 1–5 scale" changes it everywhere at once instead of criterion by
 * criterion.
 */
class KpiCriterionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'rating_scale_id' => ['nullable', 'integer', TenantRule::exists('rating_scales', 'id')],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
