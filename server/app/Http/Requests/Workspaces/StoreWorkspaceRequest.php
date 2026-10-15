<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Starting a new workspace (ADR 0089): its company name, held to the rule the
 * register form's is. A second workspace of one's own with the very same name
 * is refused, because the switcher could not tell the two apart.
 */
class StoreWorkspaceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'company name'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $name = mb_strtolower(trim((string) $this->input('name')));

                $taken = $this->user()->memberships()->get(['organizations.name'])
                    ->contains(fn ($organization): bool => mb_strtolower($organization->name) === $name);

                if ($taken) {
                    $validator->errors()->add('name', 'You already have a workspace with this name.');
                }
            },
        ];
    }
}
