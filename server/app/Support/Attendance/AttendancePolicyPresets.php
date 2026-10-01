<?php

namespace App\Support\Attendance;

/**
 * The attendance policies a company starts from (ADR 0038) — server-defined and
 * keyed, like the setup wizard's other offers (ADR 0034). A company picks one and
 * adjusts its typed options; it never writes a rule.
 *
 * Each preset is a partial settings array: anything it does not say is the
 * built-in fallback's answer ({@see AttendancePolicySettings::fromArray()}). This
 * list is the one place Philippine defaults live — the engine itself is generic.
 */
class AttendancePolicyPresets
{
    /** The preset a new Philippine company is offered first. */
    public const DEFAULT = 'ph_labor_code';

    /**
     * Every preset, in the order it is offered.
     *
     * @return list<array{key: string, name: string, description: string, highlights: list<string>, settings: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'ph_labor_code',
                'name' => 'Philippines — Labor Code',
                'description' => 'Eight hours a day, with rest-day, holiday and night minutes set apart for payroll.',
                'highlights' => [
                    'Overtime after 8 hours a day, once approved',
                    'Rest-day and holiday minutes bucketed',
                    'Night differential 22:00–06:00',
                    '1-hour unpaid lunch deducted after 5 hours',
                ],
                'settings' => [
                    'overtime' => [
                        'basis' => 'daily',
                        'daily_after_minutes' => 480,
                        'requires_approval' => true,
                    ],
                    'breaks' => [
                        'auto_deduct_minutes' => 60,
                        'auto_deduct_after_worked_minutes' => 300,
                    ],
                    'night' => ['enabled' => true, 'start' => '22:00', 'end' => '06:00'],
                ],
            ],
            [
                'key' => 'standard_40h_week',
                'name' => 'Standard 40-hour week',
                'description' => 'Hours are balanced across the week rather than day by day.',
                'highlights' => [
                    'Overtime after 40 hours a week',
                    'No night differential',
                    '30-minute unpaid break deducted after 6 hours',
                ],
                'settings' => [
                    'overtime' => [
                        'basis' => 'weekly',
                        'weekly_after_minutes' => 2400,
                    ],
                    'breaks' => [
                        'auto_deduct_minutes' => 30,
                        'auto_deduct_after_worked_minutes' => 360,
                    ],
                ],
            ],
            [
                'key' => 'flexible_no_lateness',
                'name' => 'Flexible, no lateness',
                'description' => 'People keep their own hours; only the total for the day counts.',
                'highlights' => [
                    'Nobody is marked late',
                    'Short only when the day’s hours are',
                    'Overtime after the day’s required hours',
                ],
                'settings' => [
                    'lateness' => ['enabled' => false],
                    'undertime' => ['basis' => 'hours'],
                ],
            ],
            [
                'key' => 'shift_work',
                'name' => 'Shift work',
                'description' => 'Punches rounded to the quarter hour, with daily and weekly overtime.',
                'highlights' => [
                    'Times rounded to the nearest 15 minutes',
                    'Overtime after 8 hours a day and 40 a week',
                    'A forgotten clock-out closes 2 hours after the shift',
                ],
                'settings' => [
                    'rounding' => ['mode' => 'nearest', 'unit' => 15, 'apply_to' => 'both'],
                    'overtime' => [
                        'basis' => 'daily_and_weekly',
                        'daily_after_minutes' => 480,
                        'weekly_after_minutes' => 2400,
                    ],
                    'missing_clock_out' => ['action' => 'auto_close_after_minutes', 'after_minutes' => 120],
                ],
            ],
        ];
    }

    /**
     * One preset by key, or null when it is not on offer.
     *
     * @return array{key: string, name: string, description: string, highlights: list<string>, settings: array<string, mixed>}|null
     */
    public static function find(?string $key): ?array
    {
        foreach (self::all() as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Every key on offer.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * A preset's complete settings — its overrides laid over the fallback.
     */
    public static function settings(string $key): AttendancePolicySettings
    {
        return AttendancePolicySettings::fromArray(self::find($key)['settings'] ?? []);
    }

    /**
     * The presets as the client offers them, each with its complete settings so
     * choosing one fills every field of the editor.
     *
     * @return list<array{key: string, name: string, description: string, highlights: list<string>, settings: array<string, array<string, mixed>>}>
     */
    public static function forClient(): array
    {
        return array_map(fn (array $preset): array => [
            ...$preset,
            'settings' => self::settings($preset['key'])->toArray(),
        ], self::all());
    }
}
