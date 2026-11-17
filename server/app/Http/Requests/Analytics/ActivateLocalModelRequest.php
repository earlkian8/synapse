<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching a predictive surface to the organisation's own model. `despite_check`
 * is the acknowledgement that the model did not pass its check and is wanted anyway
 * (ADR 0107); a model that passed needs none. Who may switch is the route's
 * `*.manage` permission.
 */
class ActivateLocalModelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'despite_check' => ['sometimes', 'boolean'],
        ];
    }

    public function despiteCheck(): bool
    {
        return $this->boolean('despite_check');
    }
}
