<?php

namespace App\Http\Requests\Devices;

use App\Support\Attendance\DevicePunchIngestor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of punches pushed by a device (ADR 0040). Only the envelope is
 * validated here; each row is judged on its own by {@see DevicePunchIngestor},
 * so one bad row is reported rather than refusing the batch around it.
 */
class DevicePunchesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'punches' => ['required', 'array', 'min:1', 'max:'.DevicePunchIngestor::MAX_BATCH],
            'punches.*' => ['array'],
            // The device's own clock when it sent the batch, to measure its skew.
            'sent_at' => ['nullable', 'date'],
        ];
    }
}
