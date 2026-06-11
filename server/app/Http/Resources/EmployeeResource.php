<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'initials' => $this->initials(),
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'civil_status' => $this->civil_status,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo' => $this->photo_url,

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'position' => $this->whenLoaded('position', fn () => $this->position ? [
                'id' => $this->position->id,
                'title' => $this->position->title,
            ] : null),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'full_name' => $this->manager->full_name,
                'employee_no' => $this->manager->employee_no,
            ] : null),
            'work_schedule' => $this->whenLoaded('workSchedule', fn () => $this->workSchedule ? [
                'id' => $this->workSchedule->id,
                'name' => $this->workSchedule->name,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'email' => $this->user->email,
            ] : null),

            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
            'manager_id' => $this->manager_id,
            'work_schedule_id' => $this->work_schedule_id,
            'user_id' => $this->user_id,

            'employment_type' => $this->employment_type,
            'employment_status' => $this->employment_status,
            'status' => $this->trashed() ? 'archived' : $this->employment_status,
            'date_hired' => $this->date_hired?->toDateString(),
            'date_regularized' => $this->date_regularized?->toDateString(),
            'tenure_human' => $this->date_hired?->diffForHumans(null, true),

            'basic_salary' => $this->basic_salary,
            'bank_name' => $this->bank_name,
            'bank_account_no' => $this->bank_account_no,
            'tin' => $this->tin,
            'sss_no' => $this->sss_no,
            'philhealth_no' => $this->philhealth_no,
            'pagibig_no' => $this->pagibig_no,

            'documents_count' => $this->whenCounted('documents'),
            'certifications_count' => $this->whenCounted('certifications'),
            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),
            'certifications' => EmployeeCertificationResource::collection($this->whenLoaded('certifications')),
            'promotions' => EmployeePromotionResource::collection($this->whenLoaded('promotions')),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
