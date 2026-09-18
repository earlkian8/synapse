<?php

namespace App\Support\Setup;

use App\Models\RecruitmentPipelineStage;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Performance\RatingModel;
use Illuminate\Support\Str;

/**
 * Turns what a wizard step was answered with into the definition
 * {@see SetupInstaller} writes.
 *
 * A step can be answered two ways — adopt one of the offers in
 * {@see SetupBlueprints}, or describe the company's own — and the difference
 * should stop mattering the moment the request is validated. So both arrive
 * here and leave as the same shape: a department is a name, a code and a
 * description whether it was ticked or typed; a hiring process is a name and an
 * ordered list of stages whether it came from a card or was drawn stage by
 * stage. The installer below never asks which it was.
 *
 * What does not cross the wire either way is **vocabulary with meaning attached**.
 * A blueprint is resolved from its key, and the parts of a bespoke definition
 * that the modules downstream read — a stage's kind, a criterion's instrument,
 * a statutory entitlement — are resolved from this file's own lists rather than
 * taken as content. The company's own words (its names, its descriptions, its
 * weights) are exactly what it typed.
 */
class SetupDefinition
{
    /**
     * The departments a step created: the suggestions that were ticked, in the
     * wording {@see SetupBlueprints} holds, followed by the ones the company
     * described itself.
     *
     * @param  list<string>  $codes
     * @param  list<array{name: string, code: string, description?: string|null}>  $custom
     * @return list<array{name: string, code: string, description: string|null}>
     */
    public static function departments(array $codes, array $custom): array
    {
        $definitions = [];

        foreach (SetupBlueprints::departments() as $blueprint) {
            if (in_array($blueprint['code'], $codes, true)) {
                $definitions[] = [
                    'name' => $blueprint['name'],
                    'code' => $blueprint['code'],
                    'description' => $blueprint['description'],
                ];
            }
        }

        foreach ($custom as $row) {
            $definitions[] = [
                'name' => $row['name'],
                'code' => $row['code'],
                'description' => self::text($row['description'] ?? null),
            ];
        }

        return $definitions;
    }

    /**
     * The kinds of leave a step created. A ticked suggestion keeps the policy
     * the blueprint carries — a statutory entitlement is not a thing a request
     * body gets to redefine — with only its days taken from the owner; a leave
     * type the company wrote is entirely its own.
     *
     * @param  list<string>  $codes
     * @param  array<string, float>  $days  blueprint code => annual entitlement
     * @param  list<array<string, mixed>>  $custom
     * @return list<array{name: string, code: string, description: string|null, color: string, default_days: float, is_paid: bool, allow_half_day: bool, requires_approval: bool}>
     */
    public static function leaveTypes(array $codes, array $days, array $custom): array
    {
        $definitions = [];

        foreach (SetupBlueprints::leaveTypes() as $blueprint) {
            if (! in_array($blueprint['code'], $codes, true)) {
                continue;
            }

            $definitions[] = [
                'name' => $blueprint['name'],
                'code' => $blueprint['code'],
                'description' => $blueprint['description'],
                'color' => $blueprint['color'],
                'default_days' => (float) ($days[$blueprint['code']] ?? $blueprint['default_days']),
                'is_paid' => $blueprint['is_paid'],
                'allow_half_day' => $blueprint['allow_half_day'],
                'requires_approval' => $blueprint['requires_approval'],
            ];
        }

        foreach ($custom as $row) {
            $definitions[] = [
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'description' => self::text($row['description'] ?? null),
                'color' => (string) $row['color'],
                'default_days' => (float) $row['default_days'],
                'is_paid' => (bool) ($row['is_paid'] ?? true),
                'allow_half_day' => (bool) ($row['allow_half_day'] ?? true),
                'requires_approval' => (bool) ($row['requires_approval'] ?? true),
            ];
        }

        return $definitions;
    }

    /**
     * The hiring process a step created — a name and the stages candidates move
     * through, in order.
     *
     * A blueprint's stages come from {@see SetupBlueprints}; a process the
     * company drew itself keeps its own stage names, and only the open/won/lost
     * meaning behind each is checked (by the request, against
     * {@see RecruitmentPipelineStage::KINDS}) — that is the part
     * recruitment reads.
     *
     * @param  array<string, mixed>  $answer
     * @return array{name: string, stages: list<array{name: string, kind: string}>}|null
     */
    public static function pipeline(array $answer): ?array
    {
        $name = self::text($answer['name'] ?? null);

        if (($answer['source'] ?? null) === 'custom') {
            return [
                'name' => (string) $name,
                'stages' => array_map(fn (array $stage): array => [
                    'name' => trim((string) $stage['name']),
                    'kind' => (string) $stage['kind'],
                ], array_values($answer['stages'] ?? [])),
            ];
        }

        $blueprint = SetupBlueprints::find(SetupBlueprints::pipelines(), $answer['blueprint'] ?? null);

        if ($blueprint === null) {
            return null;
        }

        return [
            'name' => $name ?? $blueprint['name'],
            'stages' => $blueprint['stages'],
        ];
    }

    /**
     * The attendance policy (and, optionally, the default schedule) the
     * Attendance step created (ADR 0038). An adopted preset's settings come from
     * {@see AttendancePolicyPresets}, not the request; a customised one keeps
     * what the company set, read back through {@see AttendancePolicySettings} so
     * it is stored complete.
     *
     * @param  array<string, mixed>  $answer
     * @return array{policy: array{name: string, preset_key: string, description: string, settings: array<string, mixed>}, schedule: array{name: string, start: string, end: string, days: list<string>}|null}|null
     */
    public static function attendance(array $answer): ?array
    {
        $preset = AttendancePolicyPresets::find($answer['preset'] ?? null);

        if ($preset === null) {
            return null;
        }

        $settings = ($answer['customised'] ?? false)
            ? AttendancePolicySettings::fromArray((array) ($answer['settings'] ?? []))
            : AttendancePolicyPresets::settings($preset['key']);

        $schedule = $answer['schedule'] ?? [];

        return [
            'policy' => [
                'name' => self::text($answer['name'] ?? null) ?? $preset['name'],
                'preset_key' => $preset['key'],
                'description' => $preset['description'],
                'settings' => $settings->toArray(),
            ],
            'schedule' => ($schedule['create'] ?? false) ? [
                'name' => trim((string) $schedule['name']),
                'start' => (string) $schedule['start'],
                'end' => (string) $schedule['end'],
                'days' => array_values(array_intersect(
                    ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    (array) ($schedule['days'] ?? []),
                )),
            ] : null,
        ];
    }

    /**
     * The appraisal framework a step created: what it is called, the instrument
     * it measures on, the weighted sections it divides an appraisal into, the
     * criteria inside them, and the words a result is reported in.
     *
     * Each line arrives resolved to the criterion behind it, so the installer
     * writes a catalogue the same way whichever route was taken. A line drawn
     * from the catalogue takes its wording from {@see SetupBlueprints::criteria()};
     * a line the company wrote keeps its own words and names one of the
     * instruments in {@see SetupBlueprints::instruments()}, falling back to the
     * framework's own.
     *
     * @param  array<string, mixed>  $answer
     * @return array{name: string, description: string|null, scale: string, result_display: string, sections: list<array{key: string, name: string, description: string|null, weight: float}>, bands: list<array<string, mixed>>, items: list<array{section: string, weight: float, name: string, description: string|null, scale: string}>}|null
     */
    public static function framework(array $answer): ?array
    {
        $name = self::text($answer['name'] ?? null);

        if (($answer['source'] ?? null) !== 'custom') {
            $blueprint = SetupBlueprints::find(SetupBlueprints::frameworks(), $answer['blueprint'] ?? null);

            if ($blueprint === null) {
                return null;
            }

            return [
                'name' => $name ?? $blueprint['name'],
                'description' => $blueprint['description'],
                'scale' => $blueprint['scale'],
                'result_display' => $blueprint['result_display'],
                'sections' => array_map(fn (array $section): array => [
                    'key' => $section['key'],
                    'name' => $section['name'],
                    'description' => $section['description'],
                    'weight' => (float) $section['weight'],
                ], $blueprint['sections']),
                'bands' => RatingModel::defaultBands(),
                'items' => array_map(fn (array $item): array => self::line(
                    $item,
                    SetupBlueprints::criterion($item['criterion']),
                    $blueprint['scale'],
                ), $blueprint['items']),
            ];
        }

        $scale = (string) $answer['scale'];

        return [
            'name' => (string) $name,
            'description' => self::text($answer['description'] ?? null),
            'scale' => $scale,
            'result_display' => (string) $answer['result_display'],
            'sections' => array_map(fn (array $section): array => [
                'key' => (string) $section['key'],
                'name' => trim((string) $section['name']),
                'description' => self::text($section['description'] ?? null),
                'weight' => (float) $section['weight'],
            ], array_values($answer['sections'] ?? [])),
            'bands' => self::bands($answer['bands'] ?? null),
            'items' => array_map(
                fn (array $item): array => self::line(
                    $item,
                    SetupBlueprints::criterion($item['criterion'] ?? null),
                    $scale,
                ),
                array_values($answer['items'] ?? []),
            ),
        ];
    }

    /**
     * One line of a framework. A catalogue criterion decides the wording and the
     * instrument; anything else is the company's own, measured on the instrument
     * it named or on the framework's when it named none.
     *
     * @param  array<string, mixed>  $item
     * @param  array{name: string, description: string, weight: float, scale: string}|null  $criterion
     * @return array{section: string, weight: float, name: string, description: string|null, scale: string}
     */
    private static function line(array $item, ?array $criterion, string $fallbackScale): array
    {
        if ($criterion !== null) {
            return [
                'section' => (string) $item['section'],
                'weight' => (float) $item['weight'],
                'name' => $criterion['name'],
                'description' => $criterion['description'],
                'scale' => $criterion['scale'],
            ];
        }

        $scale = self::text($item['scale'] ?? null);

        return [
            'section' => (string) $item['section'],
            'weight' => (float) $item['weight'],
            'name' => trim((string) ($item['name'] ?? '')),
            'description' => self::text($item['description'] ?? null),
            'scale' => $scale !== null && SetupBlueprints::scale($scale) !== null ? $scale : $fallbackScale,
        ];
    }

    /**
     * The rating model a framework reports in — the company's own bands, or the
     * standard ladder when it left them alone. Keys are derived from the labels
     * so the client never has to invent one, and the bands are ordered highest
     * first, which is the order {@see RatingModel::bandFor()} reads them in.
     *
     * @return list<array<string, mixed>>
     */
    private static function bands(mixed $bands): array
    {
        if (! is_array($bands) || $bands === []) {
            return RatingModel::defaultBands();
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($bands) as $index => $band) {
            $key = Str::slug((string) ($band['label'] ?? ''), '_') ?: "band_{$index}";

            while (in_array($key, $seen, true)) {
                $key .= '_2';
            }

            $seen[] = $key;

            $normalized[] = [
                'key' => $key,
                'label' => trim((string) $band['label']),
                'min_percent' => (float) $band['min_percent'],
                'description' => self::text($band['description'] ?? null),
                'tone' => in_array($band['tone'] ?? null, RatingModel::TONES, true) ? (string) $band['tone'] : 'neutral',
            ];
        }

        usort($normalized, fn (array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);

        return $normalized;
    }

    /**
     * A trimmed string, or null when there was nothing there — the difference
     * between "no description" and an empty one is not worth storing.
     */
    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
