<?php

namespace App\Support\CheckIns;

use App\Models\CheckIn;

/**
 * The monthly check-in's questions (ADR 0102), defined once: the web page, the
 * mobile app and validation all read them from here.
 *
 * Each is a published, validated measure, not one written for the app:
 *
 *  - **mood** — how work has felt this month, five steps from rough to great.
 *  - **satisfaction** — the single-item overall job satisfaction measure, which
 *    tracks the multi-item scales and predicts intention to quit (Dolbier, Webster,
 *    McCalister, Mallon & Steinhardt, 2005; Nagy, 2002).
 *  - **energy, enthusiasm, immersion** — the ultra-short Utrecht Work Engagement
 *    Scale (UWES-3): vigour, dedication and absorption, each "never" to "always",
 *    sharing 86–92% of its variance with the nine-item version (Schaufeli, Shimazu,
 *    Hakanen, Salanova & De Witte, 2019). Free for non-commercial use; asked about
 *    the month, not the year.
 *  - **leave_thoughts** — the first item of the Turnover Intention Scale, "How
 *    often have you considered leaving your job?" (Roodt, 2004; Bothma & Roodt,
 *    2013), never to always.
 */
final class CheckInQuestions
{
    public const MOOD = ['Rough', 'Low', 'Okay', 'Good', 'Great'];

    private const FREQUENCY = [
        ['label' => 'Never', 'hint' => null],
        ['label' => 'Almost never', 'hint' => 'A few times a year or less'],
        ['label' => 'Rarely', 'hint' => 'Once a month or less'],
        ['label' => 'Sometimes', 'hint' => 'A few times a month'],
        ['label' => 'Often', 'hint' => 'Once a week'],
        ['label' => 'Very often', 'hint' => 'A few times a week'],
        ['label' => 'Always', 'hint' => 'Every day'],
    ];

    /** "Often" or "always" on the leaving question: the share HR watches. */
    public const LEAVE_THOUGHTS_OFTEN = 4;

    /** The most a private note may hold. */
    public const NOTE_MAX = 500;

    /**
     * Every question, in the order it is asked.
     *
     * @return list<array{key: string, prompt: string, group: string, min: int, max: int, options: list<array{value: int, label: string, hint: string|null}>}>
     */
    public static function all(): array
    {
        $scale = fn (array $labels, int $from): array => array_values(array_map(
            fn (int $i, array|string $option): array => [
                'value' => $from + $i,
                'label' => is_array($option) ? $option['label'] : $option,
                'hint' => is_array($option) ? $option['hint'] : null,
            ],
            array_keys($labels),
            $labels,
        ));

        $frequency = $scale(self::FREQUENCY, 0);

        return [
            ['key' => 'mood', 'prompt' => 'How have you felt at work this month?', 'group' => 'mood',
                'min' => 1, 'max' => 5, 'options' => $scale(self::MOOD, 1)],
            ['key' => 'satisfaction', 'prompt' => 'Overall, how satisfied are you with your job?', 'group' => 'satisfaction',
                'min' => 1, 'max' => 5, 'options' => $scale(['Very dissatisfied', 'Dissatisfied', 'Neither', 'Satisfied', 'Very satisfied'], 1)],
            ['key' => 'energy', 'prompt' => 'At my work, I feel bursting with energy.', 'group' => 'engagement',
                'min' => 0, 'max' => 6, 'options' => $frequency],
            ['key' => 'enthusiasm', 'prompt' => 'I am enthusiastic about my job.', 'group' => 'engagement',
                'min' => 0, 'max' => 6, 'options' => $frequency],
            ['key' => 'immersion', 'prompt' => 'I am immersed in my work.', 'group' => 'engagement',
                'min' => 0, 'max' => 6, 'options' => $frequency],
            ['key' => 'leave_thoughts', 'prompt' => 'How often have you considered leaving your job?', 'group' => 'leaving',
                'min' => 1, 'max' => 5, 'options' => $scale(['Never', 'Rarely', 'Sometimes', 'Often', 'Always'], 1)],
        ];
    }

    /**
     * The answer columns, in order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * Validation rules for a full set of answers.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::all() as $question) {
            $rules[$question['key']] = ['required', 'integer', "between:{$question['min']},{$question['max']}"];
        }

        return $rules + ['note' => ['nullable', 'string', 'max:'.self::NOTE_MAX]];
    }

    /**
     * One check-in as its owner sees it — never shaped for anybody else.
     *
     * @return array<string, mixed>
     */
    public static function present(CheckIn $checkIn): array
    {
        return [
            'id' => $checkIn->id,
            'period' => $checkIn->period->toDateString(),
            'answers' => collect(self::keys())->mapWithKeys(fn (string $key): array => [$key => $checkIn->{$key}])->all(),
            'engagement' => $checkIn->engagement(),
            'note' => $checkIn->note,
            'submitted_at' => $checkIn->submitted_at->toIso8601String(),
        ];
    }
}
