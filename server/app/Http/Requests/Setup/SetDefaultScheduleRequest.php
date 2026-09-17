<?php

namespace App\Http\Requests\Setup;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choose (or clear) the company's default schedule — the hours anyone with no
 * assignment of their own and no department default works (ADR 0037).
 */
class SetDefaultScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_schedule_id' => [
                'nullable', 'integer',
                Rule::exists('work_schedules', 'id')
                    ->where('organization_id', app(Tenancy::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
