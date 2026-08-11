<?php

namespace App\Http\Requests\Performance;

use App\Support\Performance\EvaluationOpener;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a new appraisal for an employee within a review cycle, against an
 * appraisal framework. The scorecard is seeded server-side from the framework
 * (see {@see EvaluationOpener}), so the client only
 * picks who, when, and which framework — and the framework may be left out, in
 * which case the one that covers the employee is resolved for them.
 */
class StorePerformanceEvaluationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees', 'id')],
            'evaluation_period_id' => ['required', 'integer', TenantRule::exists('evaluation_periods', 'id')],
            'review_template_id' => ['nullable', 'integer', TenantRule::exists('review_templates', 'id')],
        ];
    }
}
