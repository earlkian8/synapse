<?php

namespace App\Http\Requests\Workspaces;

use App\Models\Organization;
use App\Support\Workspaces\Workspaces;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Leaving a workspace (ADR 0089). When nobody else is in it, leaving closes it
 * for good, so the person types its name to say they mean it.
 */
class LeaveWorkspaceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $organization = $this->route('organization');

                if (! $organization instanceof Organization || ! $this->user()->isMemberOf($organization)) {
                    return;
                }

                if (! Workspaces::isAlone($this->user(), $organization)) {
                    return;
                }

                if (mb_strtolower(trim((string) $this->input('confirm_name'))) !== mb_strtolower(trim($organization->name))) {
                    $validator->errors()->add('confirm_name', "Type {$organization->name} to confirm.");
                }
            },
        ];
    }
}
