<?php

namespace App\Http\Requests\Attendance;

use App\Support\Attendance\QrScans;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A phone's scan of a QR station's code (ADR 0103): the code as read, where the
 * phone was and how sure it was, whether it said its location was simulated,
 * and the app's install id. Judged by {@see QrScans}.
 */
class QrScanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'mocked' => ['sometimes', 'boolean'],
            'device_id' => ['nullable', 'string', 'min:16', 'max:100'],
        ];
    }
}
