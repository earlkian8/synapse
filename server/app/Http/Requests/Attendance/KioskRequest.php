<?php

namespace App\Http\Requests\Attendance;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A kiosk's name and the site it stands at (ADR 0092). The site places every
 * punch made at it; a kiosk without one places nobody.
 */
class KioskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'work_location_id' => ['nullable', 'integer', TenantRule::exists('work_locations')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the kiosk — where it stands, so people know which one it is.',
            'work_location_id.exists' => 'Choose one of your sites.',
        ];
    }
}
