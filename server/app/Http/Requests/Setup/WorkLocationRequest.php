<?php

namespace App\Http\Requests\Setup;

use App\Models\WorkLocation;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a work location (ADR 0040): its name and address, the fence
 * (a point and a radius), and the schedule and attendance policy the people
 * based there default to.
 */
class WorkLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->route('workLocation');

        return [
            'name' => [
                'required', 'string', 'max:120',
                TenantRule::unique('work_locations', 'name')->whereNull('deleted_at')->ignore($location?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'min:'.WorkLocation::MIN_RADIUS_METERS, 'max:'.WorkLocation::MAX_RADIUS_METERS],
            'default_work_schedule_id' => ['nullable', 'integer', TenantRule::exists('work_schedules', 'id')->whereNull('deleted_at')],
            'attendance_policy_id' => ['nullable', 'integer', TenantRule::exists('attendance_policies', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a location with that name.',
            'latitude.required' => 'Place the site on the map.',
            'longitude.required' => 'Place the site on the map.',
            'radius_meters.min' => 'Draw the fence at least :min m wide — a phone’s position is rarely surer than that.',
            'radius_meters.max' => 'A fence can be at most :max m across.',
        ];
    }

    /**
     * The location's own columns, ready to write.
     *
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        return [
            ...$this->safe()->only(['name', 'address', 'latitude', 'longitude', 'radius_meters', 'default_work_schedule_id', 'attendance_policy_id']),
            ...($this->has('is_active') ? ['is_active' => $this->boolean('is_active')] : []),
        ];
    }
}
