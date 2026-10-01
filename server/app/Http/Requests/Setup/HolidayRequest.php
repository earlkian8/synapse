<?php

namespace App\Http\Requests\Setup;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a holiday (Company Setup → Work Schedule & Holidays). A
 * recurring holiday repeats every year on the same month/day.
 */
class HolidayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(Holiday::TYPES)],
            'is_recurring' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_recurring' => $this->boolean('is_recurring')]);
    }
}
