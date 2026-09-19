<?php

namespace App\Http\Requests\Setup;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Who is based at a work location (ADR 0040), and for which of them it is the
 * primary site — the one whose schedule and policy they default to.
 */
class WorkLocationPeopleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['present', 'array', 'max:2000'],
            'employee_ids.*' => ['integer', 'distinct', TenantRule::exists('employees', 'id')->whereNull('deleted_at')],
            'primary_ids' => ['present', 'array'],
            'primary_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $outside = array_diff((array) $this->input('primary_ids', []), (array) $this->input('employee_ids', []));

                if ($outside !== []) {
                    $validator->errors()->add('primary_ids', 'Only somebody based here can have it as their primary site.');
                }
            },
        ];
    }
}
