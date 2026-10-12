<?php

namespace App\Support\Offboarding;

use App\Models\ExitInterview;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The exit-interview survey (ADR 0076): why somebody is leaving, how they rate
 * seven parts of their time here, whether they would recommend the company or
 * come back, and three open questions.
 *
 * Fixed rather than configurable, so answers can be read across every leaver.
 * Each {@see ExitInterview} keeps the questions as they were asked; the
 * insights read only the keys below, so wording can change without losing a
 * year of answers.
 */
final class ExitInterviewQuestionnaire
{
    /** The main reason for leaving, by key. */
    public const REASONS = [
        'career' => 'A better career move',
        'pay' => 'Pay and benefits',
        'manager' => 'My manager or leadership',
        'balance' => 'Work–life balance',
        'workload' => 'Workload or stress',
        'culture' => 'The company’s culture',
        'growth' => 'Too few chances to grow',
        'relocation' => 'Moving away',
        'personal' => 'Personal or family reasons',
        'studies' => 'Going back to school',
        'retirement' => 'Retirement',
        'contract' => 'My contract ended',
        'other' => 'Something else',
    ];

    /** The parts of their time here a leaver rates, by key. */
    public const RATINGS = [
        'role' => 'The work I did',
        'manager' => 'My manager',
        'team' => 'My team',
        'growth' => 'Chances to learn and grow',
        'pay' => 'Pay and benefits',
        'balance' => 'Work–life balance',
        'culture' => 'The company’s culture',
    ];

    /** The rating scale, 1 to 5. */
    public const SCALE = [1 => 'Very poor', 2 => 'Poor', 3 => 'Fair', 4 => 'Good', 5 => 'Very good'];

    /** A yes-or-no question's answers. */
    public const YES_MAYBE_NO = ['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'];

    /** The longest an open answer may be. */
    public const TEXT_MAX = 2000;

    /**
     * The survey, in order. `type` is `choice` (one of `options`), `rating`
     * (1–5), `yes_no` (yes / maybe / no) or `text`.
     *
     * @return list<array{key: string, type: string, label: string, required: bool, group?: string, options?: array<string, string>}>
     */
    public static function questions(): array
    {
        $questions = [[
            'key' => 'reason',
            'type' => 'choice',
            'label' => 'What is the main reason you are leaving?',
            'required' => true,
            'options' => self::REASONS,
        ]];

        foreach (self::RATINGS as $key => $label) {
            $questions[] = [
                'key' => "rating_{$key}",
                'type' => 'rating',
                'label' => $label,
                'group' => 'How would you rate…',
                'required' => true,
            ];
        }

        return [
            ...$questions,
            ['key' => 'recommend', 'type' => 'yes_no', 'label' => 'Would you recommend working here to a friend?', 'required' => true, 'options' => self::YES_MAYBE_NO],
            ['key' => 'return', 'type' => 'yes_no', 'label' => 'Would you consider coming back one day?', 'required' => true, 'options' => self::YES_MAYBE_NO],
            ['key' => 'liked', 'type' => 'text', 'label' => 'What did we do well?', 'required' => false],
            ['key' => 'improve', 'type' => 'text', 'label' => 'What should we change?', 'required' => false],
            ['key' => 'comments', 'type' => 'text', 'label' => 'Is there anything else you would like to tell us?', 'required' => false],
        ];
    }

    /**
     * Validation rules for `answers.*`, from the questions as they were asked.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public static function rules(array $questions): array
    {
        $rules = ['answers' => ['required', 'array']];

        foreach ($questions as $question) {
            $presence = ($question['required'] ?? false) ? 'required' : 'nullable';

            $rules['answers.'.$question['key']] = match ($question['type']) {
                'rating' => [$presence, 'integer', 'between:1,5'],
                'choice', 'yes_no' => [$presence, 'string', Rule::in(array_keys($question['options'] ?? []))],
                default => [$presence, 'string', 'max:'.self::TEXT_MAX],
            };
        }

        return $rules;
    }

    /**
     * What each answer's errors say, worded for the person filling it in: "Pick
     * the main reason you are leaving." rather than "The answers.reason field
     * is required."
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, string>
     */
    public static function messages(array $questions): array
    {
        $messages = ['answers.required' => 'Answer the questions, then send them.'];

        foreach ($questions as $question) {
            $field = 'answers.'.$question['key'];

            [$missing, $wrong] = match ($question['type']) {
                'rating' => ['Pick a rating for “'.$question['label'].'”.', 'Ratings go from 1 to 5.'],
                'choice' => ['Pick the main reason.', 'Pick one of the reasons listed.'],
                'yes_no' => ['Pick yes, maybe or no.', 'Pick yes, maybe or no.'],
                default => ['Answer this question.', 'Keep it under '.number_format(self::TEXT_MAX).' characters.'],
            };

            $messages["{$field}.required"] = $missing;
            $messages["{$field}.in"] = $wrong;
            $messages["{$field}.integer"] = $wrong;
            $messages["{$field}.between"] = $wrong;
            $messages["{$field}.max"] = $wrong;
        }

        return $messages;
    }

    /**
     * Only the answers the questions asked for, open answers trimmed and empty
     * ones dropped.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, int|string>
     */
    public static function clean(array $questions, array $answers): array
    {
        $out = [];

        foreach ($questions as $question) {
            $value = $answers[$question['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $out[$question['key']] = $question['type'] === 'rating' ? (int) $value : trim((string) $value);
        }

        return $out;
    }

    /**
     * The mean of an answer's ratings, to one decimal, or null.
     *
     * @param  array<string, mixed>|null  $answers
     */
    public static function averageRating(?array $answers): ?float
    {
        $ratings = collect(self::RATINGS)
            ->keys()
            ->map(fn (string $key): mixed => $answers["rating_{$key}"] ?? null)
            ->filter(fn (mixed $value): bool => is_numeric($value));

        return $ratings->isEmpty() ? null : round($ratings->avg(), 1);
    }

    /**
     * What the answered surveys say together: why people leave (count and
     * share, most common first), each rating's average and spread, and how
     * many would recommend the company or come back.
     *
     * @param  Collection<int, ExitInterview>  $submitted
     * @return array{responses: int, reasons: list<array{key: string, label: string, count: int, share: float}>, ratings: list<array{key: string, label: string, average: float|null, count: int, distribution: array<int, int>}>, recommend: array<string, int>, return: array<string, int>, average: float|null}
     */
    public static function summarize(Collection $submitted): array
    {
        $answers = $submitted->map(fn (ExitInterview $interview): array => $interview->answers ?? []);
        $responses = $answers->count();

        $reasons = $answers->pluck('reason')->filter()->countBy();
        $averages = $answers->map(fn (array $a): ?float => self::averageRating($a))->filter(fn (?float $v): bool => $v !== null);

        return [
            'responses' => $responses,
            'reasons' => $reasons
                ->sortDesc()
                ->map(fn (int $count, string $key): array => [
                    'key' => $key,
                    'label' => self::REASONS[$key] ?? $key,
                    'count' => $count,
                    'share' => $responses > 0 ? round($count / $responses, 3) : 0.0,
                ])
                ->values()
                ->all(),
            'ratings' => collect(self::RATINGS)
                ->map(function (string $label, string $key) use ($answers): array {
                    $values = $answers->pluck("rating_{$key}")->filter(fn (mixed $v): bool => is_numeric($v))->map(fn (mixed $v): int => (int) $v);

                    return [
                        'key' => $key,
                        'label' => $label,
                        'average' => $values->isEmpty() ? null : round($values->avg(), 1),
                        'count' => $values->count(),
                        'distribution' => collect(array_keys(self::SCALE))
                            ->mapWithKeys(fn (int $score): array => [$score => $values->filter(fn (int $v): bool => $v === $score)->count()])
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
            'recommend' => self::tally($answers, 'recommend'),
            'return' => self::tally($answers, 'return'),
            'average' => $averages->isEmpty() ? null : round($averages->avg(), 1),
        ];
    }

    /**
     * A plausible set of answers — for the seeder and the factory.
     *
     * @return array<string, int|string>
     */
    public static function sampleAnswers(): array
    {
        $answers = ['reason' => fake()->randomElement(array_keys(self::REASONS))];

        foreach (array_keys(self::RATINGS) as $key) {
            $answers["rating_{$key}"] = fake()->numberBetween(2, 5);
        }

        return [
            ...$answers,
            'recommend' => fake()->randomElement(['yes', 'yes', 'maybe', 'no']),
            'return' => fake()->randomElement(['yes', 'maybe', 'maybe', 'no']),
            'liked' => fake()->randomElement(['The people I worked with.', 'Flexible hours and a supportive team.', 'Training was good.']),
            'improve' => fake()->randomElement(['Clearer paths to promotion.', 'Better tools for remote work.', 'More regular feedback from managers.']),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $answers
     * @return array<string, int>
     */
    private static function tally(Collection $answers, string $key): array
    {
        $counts = $answers->pluck($key)->filter()->countBy();

        return collect(array_keys(self::YES_MAYBE_NO))
            ->mapWithKeys(fn (string $choice): array => [$choice => (int) ($counts[$choice] ?? 0)])
            ->all();
    }
}
