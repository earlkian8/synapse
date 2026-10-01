<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * The signed-in employee's own 201 profile, shaped for the mobile app. Government
 * IDs and bank details are masked to their last few characters — the app shows
 * them for recognition, never in full — and salary is intentionally omitted.
 *
 * @mixin Employee
 */
class EmployeeProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'initials' => $this->initials(),
            'employee_no' => $this->employee_no,
            'photo' => $this->photo_url,

            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,

            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'civil_status' => $this->civil_status,

            'employment_type' => $this->employment_type,
            'employment_status' => $this->employment_status,
            'date_hired' => $this->date_hired?->toDateString(),
            'date_regularized' => $this->date_regularized?->toDateString(),

            'department' => $this->whenLoaded('department', fn () => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null),
            'position' => $this->whenLoaded('position', fn () => $this->position
                ? ['id' => $this->position->id, 'title' => $this->position->title]
                : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager
                ? ['id' => $this->manager->id, 'full_name' => $this->manager->full_name]
                : null),
            'schedule' => $this->whenLoaded('workSchedule', fn () => $this->workSchedule
                ? $this->workSchedule->only(['name', 'start_time', 'end_time', 'grace_minutes', 'required_hours'])
                : null),

            // Government & bank identifiers, masked for at-a-glance recognition.
            'government_ids' => [
                'tin' => $this->mask($this->tin),
                'sss_no' => $this->mask($this->sss_no),
                'philhealth_no' => $this->mask($this->philhealth_no),
                'pagibig_no' => $this->mask($this->pagibig_no),
            ],
            'bank' => [
                'name' => $this->bank_name,
                'account_no' => $this->mask($this->bank_account_no),
            ],
        ];
    }

    /**
     * Mask all but the last four characters of a sensitive identifier.
     */
    private function mask(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (Str::length($value) <= 4) {
            return str_repeat('•', Str::length($value));
        }

        return str_repeat('•', Str::length($value) - 4).Str::substr($value, -4);
    }
}
