<?php

namespace App\Http\Requests\RolePermission;

use App\Support\PermissionRegistry;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /**
     * The rules a new role is held to — by the screen and the assistant alike.
     * A key is unique within this workspace: roles are per-organisation, and a
     * global check would both collide with and reveal other companies' roles.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', TenantRule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionRegistry::names())],
        ];
    }

    /**
     * Derive a machine name from the label when one is not supplied.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::slug($this->string('name')->whenEmpty(
                fn () => $this->string('label')
            )->toString()),
        ]);
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::messagesFor();
    }

    /**
     * @return array<string, string>
     */
    public static function messagesFor(): array
    {
        return [
            'name.regex' => 'The role key must be lowercase words separated by hyphens.',
            'name.unique' => 'A role with this key already exists.',
        ];
    }
}
