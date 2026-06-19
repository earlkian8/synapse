<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate an edit to the organisation's company profile (the tenant doubles as
 * the company profile — ADR 0005). Authorization is the route's
 * `can:setup.company.manage`.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tin' => ['nullable', 'string', 'max:50'],
            'sss_employer_no' => ['nullable', 'string', 'max:50'],
            'philhealth_employer_no' => ['nullable', 'string', 'max:50'],
            'pagibig_employer_no' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }
}
