<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkEmployeeActionRequest extends FormRequest
{
    /**
     * Supported bulk actions.
     *
     * @var list<string>
     */
    public const ACTIONS = ['archive', 'restore', 'delete', 'set-status'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required_if:action,set-status', 'nullable', Rule::in(StoreEmployeeRequest::EMPLOYMENT_STATUSES)],
        ];
    }
}
