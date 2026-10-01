<?php

namespace App\Http\Requests\Setup;

use App\Support\OrganizationClock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate an edit to the organisation's company profile (the tenant doubles as
 * the company profile — ADR 0005). Authorization is the route's
 * `can:setup.company.manage`.
 *
 * `timezone` is required on both screens that use this request: it is the clock
 * attendance is judged on (ADR 0036), and it must be one of PHP's canonical IANA
 * identifiers so it has exactly one spelling.
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
            'timezone' => ['required', 'string', Rule::in(OrganizationClock::identifiers())],
            'tin' => ['nullable', 'string', 'max:50'],
            'sss_employer_no' => ['nullable', 'string', 'max:50'],
            'philhealth_employer_no' => ['nullable', 'string', 'max:50'],
            'pagibig_employer_no' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.in' => 'Choose a time zone from the list.',
        ];
    }
}
