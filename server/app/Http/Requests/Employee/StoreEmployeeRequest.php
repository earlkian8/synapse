<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public const GENDERS = ['male', 'female', 'other'];

    public const CIVIL_STATUSES = ['single', 'married', 'widowed', 'separated', 'divorced'];

    public const EMPLOYMENT_TYPES = ['regular', 'probationary', 'contractual', 'part_time'];

    public const EMPLOYMENT_STATUSES = ['active', 'on_leave', 'suspended', 'resigned', 'terminated'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_no' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_no')],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(self::GENDERS)],
            'civil_status' => ['nullable', Rule::in(self::CIVIL_STATUSES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'work_schedule_id' => ['nullable', 'integer', Rule::exists('work_schedules', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')],

            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],
            'employment_status' => ['required', Rule::in(self::EMPLOYMENT_STATUSES)],
            'date_hired' => ['required', 'date'],
            'date_regularized' => ['nullable', 'date', 'after_or_equal:date_hired'],

            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:64'],
            'tin' => ['nullable', 'string', 'max:32'],
            'sss_no' => ['nullable', 'string', 'max:32'],
            'philhealth_no' => ['nullable', 'string', 'max:32'],
            'pagibig_no' => ['nullable', 'string', 'max:32'],
        ];
    }
}
