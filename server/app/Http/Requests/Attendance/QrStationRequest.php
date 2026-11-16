<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceQrStation;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A QR station's name, the site its screen stands at, and how often its code
 * changes (ADR 0103). The site is required: a scan is judged by whether the
 * phone is inside it, and with no site there is nothing to judge against.
 */
class QrStationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'work_location_id' => ['required', 'integer', TenantRule::exists('work_locations')->whereNull('deleted_at')],
            'rotate_seconds' => ['required', 'integer', Rule::in(AttendanceQrStation::ROTATE_CHOICES)],
            'scan_mode' => ['sometimes', 'string', Rule::in(AttendanceQrStation::SCAN_MODES)],
            'same_network' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the station — where its screen is, so people know which one it is.',
            'work_location_id.required' => 'Choose the site the screen stands at. Scans are accepted only from inside it.',
            'work_location_id.exists' => 'Choose one of your sites.',
            'rotate_seconds.in' => 'Choose how often the code changes.',
        ];
    }
}
