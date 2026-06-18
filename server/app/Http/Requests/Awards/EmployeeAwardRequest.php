<?php

namespace App\Http\Requests\Awards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Give a recognition to an employee, or edit one. `employee_id` is only required
 * when giving a new award (on update the recipient is fixed). The existence checks
 * are confined to the current tenant by the models' global scope at the controller.
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
            'employee_id' => [Rule::requiredIf($giving), 'integer', Rule::exists('employees', 'id')],
            'award_type_id' => ['required', 'integer', Rule::exists('award_types', 'id')],
            'awarded_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
