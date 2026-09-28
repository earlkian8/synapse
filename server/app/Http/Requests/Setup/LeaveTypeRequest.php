<?php

namespace App\Http\Requests\Setup;

use App\Models\LeaveType;
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
        return self::rulesFor($this->route('leaveType'));
    }

    /**
     * The rules for creating a leave type (null) or editing this one. Static so
     * a caller with no route — the assistant — holds a type to exactly these.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(?LeaveType $type): array
    {
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
     * A code as it is stored: trimmed and upper-cased.
     */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Normalise the code (trimmed, uppercase) and coerce the policy flags.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('code')) {
            $merge['code'] = self::normaliseCode((string) $this->input('code'));
        }

        foreach (['is_paid', 'allow_half_day', 'requires_approval', 'is_active'] as $flag) {
            $merge[$flag] = $this->boolean($flag);
        }

        $this->merge($merge);
    }
}
