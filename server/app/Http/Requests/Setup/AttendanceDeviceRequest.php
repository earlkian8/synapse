<?php

namespace App\Http\Requests\Setup;

use App\Models\AttendanceDevice;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Register or update a kiosk or biometric device (ADR 0040): what it is called,
 * what kind it is, where it stands, and whether it may send punches.
 */
class AttendanceDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $device = $this->route('attendanceDevice');

        return [
            'name' => [
                'required', 'string', 'max:120',
                TenantRule::unique('attendance_devices', 'name')->whereNull('deleted_at')->ignore($device?->id),
            ],
            // The kind is fixed once registered: a kiosk's key opens the kiosk.
            'type' => [$device === null ? 'required' : 'prohibited', Rule::in(AttendanceDevice::TYPES)],
            'work_location_id' => ['nullable', 'integer', TenantRule::exists('work_locations', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a device with that name.',
            'type.prohibited' => 'A device’s kind cannot change. Register a new one instead.',
        ];
    }
}
