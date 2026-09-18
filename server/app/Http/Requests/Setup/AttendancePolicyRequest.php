<?php

namespace App\Http\Requests\Setup;

use App\Models\AttendancePunch;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update an attendance policy (ADR 0038): a name, the preset it came
 * from, whether it is the company default, and its typed settings.
 *
 * **The one place a policy's settings are validated.** The Company Setup editor
 * posts here, the setup wizard's Attendance step extends this request, and the
 * worked example borrows {@see settingsRules()} / {@see validateSettings()} — so a
 * policy cannot be valid in one place and not another.
 *
 * What is stored is the settings as {@see AttendancePolicySettings} reads them
 * back ({@see settings()}), so the JSON on disk is always complete and canonical
 * however much of it the client sent.
 */
class AttendancePolicyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $policy = $this->route('attendancePolicy');
        $organization = app(Tenancy::class)->id();

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('attendance_policies', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organization)->whereNull('deleted_at'))
                    ->ignore($policy?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'preset_key' => ['nullable', Rule::in(AttendancePolicyPresets::keys())],
            'is_default' => ['boolean'],
            ...self::settingsRules(),
        ];
    }

    /**
     * The rules every settings document is held to, under `settings.*`.
     *
     * @return array<string, mixed>
     */
    public static function settingsRules(string $prefix = 'settings'): array
    {
        $minutes = fn (int $min, int $max, bool $nullable = false): array => [
            $nullable ? 'nullable' : 'required', 'integer', "min:{$min}", "max:{$max}",
        ];

        return [
            $prefix => ['required', 'array'],

            "{$prefix}.punch_windows.early_clock_in_minutes" => $minutes(0, 720),
            "{$prefix}.punch_windows.max_shift_span_minutes" => $minutes(240, 1440),

            "{$prefix}.lateness.enabled" => ['required', 'boolean'],
            "{$prefix}.lateness.grace_minutes" => $minutes(0, 240, nullable: true),
            "{$prefix}.lateness.grace_mode" => ['required', Rule::in(AttendancePolicySettings::GRACE_MODES)],
            "{$prefix}.lateness.monthly_grace_minutes" => $minutes(0, 1440),
            "{$prefix}.lateness.half_day_after_minutes" => $minutes(1, 1440, nullable: true),
            "{$prefix}.lateness.absent_after_minutes" => $minutes(1, 1440, nullable: true),

            "{$prefix}.undertime.basis" => ['required', Rule::in(AttendancePolicySettings::UNDERTIME_BASES)],
            "{$prefix}.undertime.half_day_below_minutes" => $minutes(1, 1440, nullable: true),
            "{$prefix}.undertime.minimum_minutes_for_present" => $minutes(1, 1440, nullable: true),

            "{$prefix}.rounding.mode" => ['required', Rule::in(AttendancePolicySettings::ROUNDING_MODES)],
            "{$prefix}.rounding.unit" => ['required', 'integer', Rule::in(AttendancePolicySettings::ROUNDING_UNITS)],
            "{$prefix}.rounding.apply_to" => ['required', Rule::in(AttendancePolicySettings::ROUNDING_TARGETS)],

            "{$prefix}.breaks.paid_break_minutes" => $minutes(0, 480),
            "{$prefix}.breaks.auto_deduct_minutes" => $minutes(0, 480),
            "{$prefix}.breaks.auto_deduct_after_worked_minutes" => $minutes(0, 1440),
            "{$prefix}.breaks.max_break_minutes" => $minutes(1, 480, nullable: true),

            "{$prefix}.overtime.basis" => ['required', Rule::in(AttendancePolicySettings::OVERTIME_BASES)],
            "{$prefix}.overtime.daily_after_minutes" => $minutes(0, 1440, nullable: true),
            "{$prefix}.overtime.weekly_after_minutes" => $minutes(0, 10080),
            "{$prefix}.overtime.min_block_minutes" => $minutes(0, 480),
            "{$prefix}.overtime.count_early_clock_in" => ['required', 'boolean'],
            "{$prefix}.overtime.requires_approval" => ['required', 'boolean'],
            "{$prefix}.overtime.rest_day_all_overtime" => ['required', 'boolean'],
            "{$prefix}.overtime.holiday_all_overtime" => ['required', 'boolean'],

            "{$prefix}.missing_clock_out.action" => ['required', Rule::in(AttendancePolicySettings::MISSING_CLOCK_OUT_ACTIONS)],
            "{$prefix}.missing_clock_out.after_minutes" => $minutes(0, 1440),

            "{$prefix}.night.enabled" => ['required', 'boolean'],
            "{$prefix}.night.start" => ['required', 'date_format:H:i'],
            "{$prefix}.night.end" => ['required', 'date_format:H:i', "different:{$prefix}.night.start"],

            "{$prefix}.capture.allowed_sources" => ['required', 'array', 'min:1'],
            "{$prefix}.capture.allowed_sources.*" => ['string', Rule::in(AttendancePunch::CAPTURE_SOURCES)],
            "{$prefix}.capture.selfie_required" => ['required', 'boolean'],
            "{$prefix}.capture.geofence" => ['required', Rule::in(AttendancePolicySettings::GEOFENCE_MODES)],
            "{$prefix}.capture.web_ip_allowlist" => ['nullable', 'array', 'max:50'],
            "{$prefix}.capture.web_ip_allowlist.*" => ['string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! self::isAddressOrRange((string) $value)) {
                    $fail('Each allowed address must be an IP address, or a range such as 203.0.113.0/24.');
                }
            }],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty()) {
                    self::validateSettings($validator, (array) $this->input('settings', []));
                }
            },
        ];
    }

    /**
     * The checks that span more than one field — thresholds that would contradict
     * each other.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function validateSettings(Validator $validator, array $settings, string $prefix = 'settings'): void
    {
        $halfDayLate = data_get($settings, 'lateness.half_day_after_minutes');
        $absentLate = data_get($settings, 'lateness.absent_after_minutes');

        if ($halfDayLate !== null && $absentLate !== null && (int) $absentLate <= (int) $halfDayLate) {
            $validator->errors()->add(
                "{$prefix}.lateness.absent_after_minutes",
                'An absence has to take more lateness than a half day does.',
            );
        }

        $halfDayShort = data_get($settings, 'undertime.half_day_below_minutes');
        $minimum = data_get($settings, 'undertime.minimum_minutes_for_present');

        if ($halfDayShort !== null && $minimum !== null && (int) $minimum >= (int) $halfDayShort) {
            $validator->errors()->add(
                "{$prefix}.undertime.minimum_minutes_for_present",
                'The minimum for being present has to be below the half-day line.',
            );
        }

        if (data_get($settings, 'lateness.grace_mode') === 'monthly_allowance'
            && (int) data_get($settings, 'lateness.monthly_grace_minutes', 0) === 0
            && (bool) data_get($settings, 'lateness.enabled', true)) {
            $validator->errors()->add(
                "{$prefix}.lateness.monthly_grace_minutes",
                'Give the monthly allowance some minutes, or forgive lateness per day instead.',
            );
        }
    }

    /**
     * The settings as they will be stored — complete and canonical.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(string $key = 'settings'): array
    {
        return AttendancePolicySettings::fromArray((array) $this->validated($key))->toArray();
    }

    /**
     * The policy's own columns, ready to write.
     *
     * @return array<string, mixed>
     */
    public function policyAttributes(): array
    {
        return [
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
            'preset_key' => $this->validated('preset_key'),
            'settings' => $this->settings(),
            'settings_version' => AttendancePolicySettings::VERSION,
            // Only when it was sent: saving a policy's rules must not quietly stop
            // it being the company default.
            ...($this->has('is_default') ? ['is_default' => $this->boolean('is_default')] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a policy with that name.',
            'settings.capture.allowed_sources.min' => 'Allow at least one way to punch.',
            'settings.night.end.different' => 'The night window has to end at a different time from when it starts.',
        ];
    }

    /**
     * An IP address, or a CIDR range of one.
     */
    private static function isAddressOrRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', trim($value), 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }
}
