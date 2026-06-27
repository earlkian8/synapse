<?php

namespace App\Http\Requests\UserManagement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUserActionRequest extends FormRequest
{
    /**
     * Supported bulk actions.
     *
     * @var list<string>
     */
    public const ACTIONS = ['activate', 'deactivate', 'archive', 'restore', 'delete', 'assign-role'];

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
            // Only the assign-role action carries a target role.
            'role_id' => ['required_if:action,assign-role', 'integer', Rule::exists('roles', 'id')],
        ];
    }
}
