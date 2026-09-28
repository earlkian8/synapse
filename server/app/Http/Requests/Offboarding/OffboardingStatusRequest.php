<?php

namespace App\Http\Requests\Offboarding;

use App\Support\Offboarding\OffboardingWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move an exit through its lifecycle: complete, cancel or reopen it. Whether the
 * move is allowed from where the exit is now is {@see OffboardingWorkflow}'s
 * call.
 */
class OffboardingStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(array_keys(OffboardingWorkflow::ACTIONS))],
        ];
    }
}
