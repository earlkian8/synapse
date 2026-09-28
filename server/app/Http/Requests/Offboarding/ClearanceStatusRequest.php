<?php

namespace App\Http\Requests\Offboarding;

use App\Models\ClearanceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sign a clearance item off, flag it, or put it back to pending, optionally with
 * remarks (the sign-off note, or why it is flagged).
 */
class ClearanceStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ClearanceItem::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
