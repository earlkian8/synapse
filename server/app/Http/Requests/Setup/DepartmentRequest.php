<?php

namespace App\Http\Requests\Setup;

use App\Models\Department;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a department. `code` is unique per tenant (ignoring archived
 * rows); `parent_id` may not point at the department itself or any of its
 * descendants — that would create a cycle.
 */
class DepartmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $orgId = app(Tenancy::class)->id();

        $parentRule = ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')];

        if ($department instanceof Department) {
            $parentRule[] = Rule::notIn($department->subtreeIds());
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('departments', 'code')
                    ->where(fn ($query) => $query->where('organization_id', $orgId)->whereNull('deleted_at'))
                    ->ignore($department?->id),
            ],
            'parent_id' => $parentRule,
            'head_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Normalise the code to a trimmed, uppercase value before validating.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'A department cannot be nested under itself or one of its sub-departments.',
        ];
    }
}
