<?php

namespace App\Http\Requests\Setup;

use App\Support\Attendance\DeviceCsvImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A device's CSV export and how its columns map onto a punch (ADR 0040). The
 * columns are named by their header; the time is either one column, or a date
 * column and a time column. The mapping is remembered on the device, so the next
 * file from it needs none.
 */
class ImportDevicePunchesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['required', 'array'],
            'mapping.employee_ref' => ['required', 'string', 'max:120'],
            'mapping.punched_at' => ['nullable', 'string', 'max:120'],
            'mapping.date' => ['nullable', 'string', 'max:120'],
            'mapping.time' => ['nullable', 'string', 'max:120'],
            'mapping.type' => ['nullable', 'string', 'max:120'],
            'mapping.external_id' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mapping = (array) $this->input('mapping', []);

                if (blank($mapping['punched_at'] ?? null) && (blank($mapping['date'] ?? null) || blank($mapping['time'] ?? null))) {
                    $validator->errors()->add('mapping.punched_at', 'Choose the column with the time of each punch, or a date column and a time column.');
                }
            },
        ];
    }

    /**
     * The mapping as {@see DeviceCsvImport} reads it.
     *
     * @return array<string, ?string>
     */
    public function mapping(): array
    {
        return array_map(
            fn ($column): ?string => filled($column) ? (string) $column : null,
            array_intersect_key((array) $this->validated('mapping'), array_flip(DeviceCsvImport::FIELDS)),
        );
    }
}
