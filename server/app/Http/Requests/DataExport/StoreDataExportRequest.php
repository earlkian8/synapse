<?php

namespace App\Http\Requests\DataExport;

use App\Models\DataExport;
use App\Support\DataExport\DataExportCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What to put in a Data Export. A dataset is accepted only if the asker can view
 * the screen its records are read on, so a tampered payload cannot reach past
 * their role ({@see DataExportCatalogue::allows()}).
 */
class StoreDataExportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = array_keys(DataExportCatalogue::for($this->user()));

        return [
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['required', 'string', 'distinct', Rule::in($allowed)],
            'format' => ['required', 'string', Rule::in(DataExport::FORMATS)],
            'include_files' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datasets.required' => 'Choose at least one kind of record to export.',
            'datasets.min' => 'Choose at least one kind of record to export.',
            'datasets.*.in' => 'You cannot export one of the kinds of record chosen.',
            'format.in' => 'Choose CSV or JSON.',
        ];
    }
}
