<?php

namespace App\Http\Requests\Awards;

use App\Support\Awards\AwardWorkflow;
use App\Support\OrganizationClock;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Give a recognition to an employee, or edit one. `employee_id` is only required
 * when giving a new award (on update the recipient is fixed). The existence checks
 * are confined to the current tenant ({@see TenantRule}); validation runs as raw
 * queries, so the models' global scope never sees them. "Today" is the
 * organisation's today, not the server's — an award given on a Manila morning is
 * not "in the future" because UTC is still on yesterday. Whether the award type
 * may still be given out is {@see AwardWorkflow}'s call.
 */
class EmployeeAwardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $giving = $this->isMethod('post');

        return [
            'employee_id' => [Rule::requiredIf($giving), 'integer', TenantRule::exists('employees')],
            'award_type_id' => ['required', 'integer', TenantRule::exists('award_types')],
            'awarded_on' => ['required', 'date', 'before_or_equal:'.OrganizationClock::today()],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
