<?php

namespace App\Http\Requests\RolePermission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkRoleActionRequest extends FormRequest
{
    /**
     * Supported bulk actions.
     *
     * @var list<string>
     */
    public const ACTIONS = ['delete'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ];
    }
}
