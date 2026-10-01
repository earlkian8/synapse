<?php

namespace App\Http\Requests\Performance;

use App\Support\Performance\TemplateResolver;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Launch a review cycle: open appraisals for a whole population at once rather
 * than one employee at a time, which is the only way a cycle of any size is
 * actually run.
 *
 * The scope decides who is drawn in — everyone active, or the active staff of
 * chosen departments. The framework may be pinned for the whole launch, or left
 * out so each employee gets the one that covers them
 * (see {@see TemplateResolver}).
 */
class LaunchReviewCycleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'evaluation_period_id' => ['required', 'integer', TenantRule::exists('evaluation_periods', 'id')],
            'review_template_id' => ['nullable', 'integer', TenantRule::exists('review_templates', 'id')],
            'scope' => ['required', Rule::in(['all', 'departments'])],
            'department_ids' => ['nullable', 'array', 'required_if:scope,departments', 'max:50'],
            'department_ids.*' => ['integer', TenantRule::exists('departments', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_ids.required_if' => 'Choose at least one department to launch for.',
        ];
    }
}
