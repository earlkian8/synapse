<?php

namespace App\Http\Requests\CheckIns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether the workspace runs monthly check-ins (ADR 0102). The five-answer bar for
 * showing a group is deliberately not a setting.
 */
class CheckInSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['check_ins_enabled' => ['required', 'boolean']];
    }
}
