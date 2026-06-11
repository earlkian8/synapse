<?php

namespace App\Http\Requests\Setup;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a leave type. `code` is unique per tenant (ignoring archived
 * rows). The boolean policy flags are normalised before validation so an omitted
 * checkbox reads as `false`.
 */
class LeaveTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->route('leaveType');
        $orgId = app(Tenancy::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('leave_types', 'code')
                    ->where(fn ($query) => $query->where('organization_id', $orgId)->whereNull('deleted_at'))
                    ->ignore($type?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', 'string', 'max:20'],
            'default_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['boolean'],
            'allow_half_day' => ['boolean'],
            'requires_approval' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Normalise the code (trimmed, uppercase) and coerce the policy flags.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('code')) {
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        }

        foreach (['is_paid', 'allow_half_day', 'requires_approval', 'is_active'] as $flag) {
            $merge[$flag] = $this->boolean($flag);
        }

        $this->merge($merge);
    }
}
