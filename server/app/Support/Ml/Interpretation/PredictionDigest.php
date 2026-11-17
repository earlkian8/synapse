<?php

namespace App\Support\Ml\Interpretation;

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\LocalModel;
use App\Models\PerformanceForecast;
use App\Models\PerformanceForecastRun;
use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Models\User;
use App\Services\Assistant\Security\UntrustedText;
use App\Support\Ml\PredictionContext;
use App\Support\Ml\PredictionWording;
use Illuminate\Database\Eloquent\Model;

/**
 * What the LLM is told about one person's prediction (ADR 0106): the result as the
 * page states it, its reasons, what the model learned from, and the record the
 * viewer may see beside it — as compact, labelled lines.
 *
 * The reasons are numbered (`[R1]`, `[R2]`…) and returned alongside the text with
 * their labels, effects and points, so the interpretation's list of reasons is built
 * here from the stored result: the model only writes *why* each one moves the score,
 * and cannot add one or change a number.
 *
 * Privacy, by construction rather than by instruction:
 *  - the person is never named — no name, employee number or photo is sent;
 *  - pay never leaves (the assistant's rule, {@see PredictionWording::WITHHELD_INPUTS}):
 *    not its amount, band, points or direction, nor any indicator, scenario or input
 *    built from it. Its reason is listed with a fixed note instead;
 *  - leave is sent as days, never by type (a type can be a health matter);
 *  - the manager is not named;
 *  - every block of the record comes through {@see PredictionContext}, gated by the
 *    viewer's own permissions, and every typed value is cleaned to one bounded line.
 */
final class PredictionDigest
{
    /** The most reasons sent and listed, strongest first. */
    public const MAX_REASONS = 5;

    private const SURFACE_TITLES = [
        'attrition' => 'Attrition risk',
        'promotion' => 'Promotion readiness',
        'performance' => 'Performance forecast',
    ];

    private const SCENARIOS = [
        'promotion' => 'A promotion now',
        'regular' => 'Regular employment',
        'pay_band' => 'A raise into the next pay band',
        'together' => 'All of these together',
    ];

    /**
     * @param  array<string, mixed>  $context  {@see PredictionContext::for()} for the viewer
     * @return array{text: string, reasons: list<array{key: string, label: string, effect: string|null, points: float|null, fixed: string|null}>}
     */
    public function build(string $surface, Model $score, User $viewer, array $context): array
    {
        [$lines, $reasons] = match ($surface) {
            'attrition' => $this->attrition($score),
            'promotion' => $this->promotion($score),
            'performance' => $this->performance($score),
        };

        $lines = [...$lines, '', ...$this->record($surface, $viewer, $context)];

        return ['text' => implode("\n", $lines), 'reasons' => $reasons];
    }

    // ── The three surfaces ───────────────────────────────────────────────────

    /**
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function attrition(AttritionRiskScore $score): array
    {
        /** @var AttritionRiskRun $run */
        $run = $score->run;
        $reference = $run->reference ?? [];
        $explanation = $score->explanation ?? [];
        $tier = (string) $score->tier;

        $lines = [
            'PREDICTION: Attrition risk — how likely this employee is to resign within about a year, as a 0–100 score (higher means more likely to leave).',
            ...$this->model($run, 'a survey of employees at other workplaces'),
            'RESULT: '.PredictionWording::score((float) $score->score).' / 100 — "'.(PredictionWording::RISK_TIERS[$tier] ?? $tier).'" ('
                .(PredictionWording::RISK_MEANING[$tier] ?? '').'). Chance of leaving: '.PredictionWording::percent((float) $score->probability).'.',
        ];

        if (isset($explanation['baseline'])) {
            $lines[] = 'A TYPICAL EMPLOYEE (every input at its usual value) scores '.PredictionWording::score((float) $explanation['baseline']).'.';
        }
        if (isset($reference['tiers']['medium_from'], $reference['tiers']['high_from'])) {
            $lines[] = 'TIERS: "At watch" from '.PredictionWording::score((float) $reference['tiers']['medium_from'])
                .', "High risk" from '.PredictionWording::score((float) $reference['tiers']['high_from']).'.';
        }
        $lines[] = 'CONFIDENCE: '.PredictionWording::percent((float) $score->confidence).' of the inputs came from their own record'
            .((float) $score->confidence < 1 ? '; the rest were filled with typical values.' : '.');

        $exact = isset($explanation['baseline']);
        $lines[] = '';
        $lines[] = $exact
            ? 'REASONS (exact: each is the risk points it adds to or takes from a typical employee’s score; together they add up to this score):'
            : 'REASONS (approximate effects; this run is from before scores were broken down exactly):';

        $reasons = [];
        $withheld = false;

        foreach (array_slice($score->factors ?? [], 0, self::MAX_REASONS) as $factor) {
            $feature = (string) ($factor['feature'] ?? '');
            $points = round((float) ($factor['impact'] ?? 0) * 100, 1);
            $effect = ($factor['direction'] ?? ($points >= 0 ? 'up' : 'down')) === 'up' ? 'raises' : 'lowers';

            if ($this->isPay($feature, $factor['group'] ?? null)) {
                // Listed, never tagged: the model is not asked to explain it.
                $withheld = true;
                $reasons[] = ['key' => 'pay', 'label' => 'Pay', 'effect' => $effect, 'points' => $points,
                    'fixed' => 'Pay is one of the system’s inputs. Its effect is shown in the Reasons tab; pay is never sent to the AI, so it is not explained here.'];

                continue;
            }

            $key = 'R'.(count(array_filter($reasons, fn (array $r): bool => $r['key'] !== 'pay')) + 1);
            $label = $this->clean($factor['label'] ?? null, 80) ?: $feature;
            $line = "[{$key}] {$label}: ".$this->attritionValue($feature, $factor['value'] ?? null, $factor['components'] ?? null);
            if (isset($factor['typical'])) {
                $line .= ' (typical: '.$this->attritionValue($feature, $factor['typical'], $factor['typical_components'] ?? null).')';
            }
            $line .= ' → '.$this->signed($points).' risk points.'.$this->evidence($factor);

            $lines[] = $line;
            $reasons[] = ['key' => $key, 'label' => $label, 'effect' => $effect, 'points' => $points, 'fixed' => null];
        }

        if ($withheld) {
            $lines[] = '[not shared] Pay is also among the reasons. It is withheld from you: never guess its amount, level or direction.';
        }

        $lines = [...$lines, ...$this->indicators($explanation['indicators'] ?? [], $reference['indicators'] ?? [])];

        $scenarios = collect($explanation['scenarios'] ?? [])
            ->filter(fn (mixed $s): bool => is_array($s) && isset($s['key'], $s['score']) && ! $this->touchesPay($s['changes'] ?? []))
            ->map(fn (array $s): string => (self::SCENARIOS[$s['key']] ?? (string) $s['key']).' → '.PredictionWording::score((float) $s['score']))
            ->values();

        if ($scenarios->isNotEmpty()) {
            $lines[] = 'WHAT-IF (the system’s score for the same record with one change HR can make): '.$scenarios->implode('; ').'.';
        }

        $evidence = $reference['evidence'] ?? null;
        if (is_array($evidence) && isset($evidence['people'])) {
            $lines[] = ($evidence['source'] ?? 'survey') === 'own_records'
                ? "LEARNED FROM: the organisation's own records of {$evidence['people']} people."
                : "LEARNED FROM: a survey of {$evidence['people']} people at other workplaces, ".PredictionWording::percent((float) ($evidence['overall_rate'] ?? 0)).' of whom left.';
        }

        return [$lines, $reasons];
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function promotion(PromotionReadinessScore $score): array
    {
        /** @var PromotionReadinessRun $run */
        $run = $score->run;
        $reference = $run->reference ?? [];
        $explanation = $score->explanation ?? [];
        $tier = (string) $score->tier;
        $baseRate = $run->baseRate();

        $lines = [
            'PREDICTION: Promotion readiness — how likely this employee is to be promoted within about a year, as a 0–100 score (higher means more ready). It reads only completed appraisals.',
            ...$this->model($run, 'a general workforce dataset of employees at other organisations'),
            'RESULT: '.PredictionWording::score((float) $score->score).' / 100 — "'.(PredictionWording::READINESS_TIERS[$tier] ?? $tier).'" readiness ('
                .(PredictionWording::READINESS_MEANING[$tier] ?? '').'). Of people with this record, '.PredictionWording::percent((float) $score->probability).' were promoted'
                .($baseRate !== null ? ', against an average of '.PredictionWording::percent($baseRate) : '').'.',
            'BASIS: '.($score->basis === 'two_appraisals'
                ? 'two appraisals — the latest and the change since the one before.'
                : 'the latest appraisal only — with one more appraisal the system would also read the change between them.'),
        ];

        if (isset($explanation['baseline'])) {
            $lines[] = 'A TYPICAL RECORD with the same history scores '.PredictionWording::score((float) $explanation['baseline']).'.';
        }
        if (isset($reference['tier_scores']['medium'], $reference['tier_scores']['high'])) {
            $lines[] = 'TIERS: "Medium" from '.PredictionWording::score((float) $reference['tier_scores']['medium'])
                .', "High" from '.PredictionWording::score((float) $reference['tier_scores']['high']).'.';
        }

        $lines[] = '';
        $lines[] = 'REASONS (readiness points each input adds to or takes from the typical record’s score):';
        $reasons = [];

        foreach (array_slice($score->factors ?? [], 0, self::MAX_REASONS) as $factor) {
            $points = round((float) ($factor['impact'] ?? 0), 1);
            $key = 'R'.(count($reasons) + 1);
            $label = $this->clean($factor['label'] ?? null, 80) ?: (string) ($factor['feature'] ?? '');
            $unit = ($factor['feature'] ?? '') === 'rating_change' ? ' points' : '%';
            $value = isset($factor['value']) ? $this->signedIf($factor['feature'] ?? '', (float) $factor['value']).$unit : 'recorded';
            $typical = isset($factor['typical']) ? ' (typical: '.$this->signedIf($factor['feature'] ?? '', (float) $factor['typical']).$unit.')' : '';

            $lines[] = "[{$key}] {$label}: {$value}{$typical} → ".$this->signed($points).' readiness points.';
            $reasons[] = ['key' => $key, 'label' => $label, 'effect' => ($factor['direction'] ?? 'up') === 'up' ? 'raises' : 'lowers', 'points' => $points, 'fixed' => null];
        }

        $outlook = $explanation['outlook'] ?? null;
        if (is_array($outlook)) {
            $lines[] = 'NEXT APPRAISAL: '.collect([
                isset($outlook['same_again']) ? 'if it repeats the latest rating, readiness would be '.PredictionWording::score((float) $outlook['same_again']) : null,
                isset($outlook['medium_from']) ? 'a rating of '.PredictionWording::number((float) $outlook['medium_from']).'% or more reaches Medium' : null,
                isset($outlook['high_from']) ? 'a rating of '.PredictionWording::number((float) $outlook['high_from']).'% or more reaches High' : 'no rating on the scale reaches High',
            ])->filter()->implode('; ').'.';
        }

        $history = collect($score->history ?? [])->map(fn (mixed $h): string => is_array($h)
            ? $this->clean($h['label'] ?? 'Appraisal', 40).' '.PredictionWording::number((float) ($h['rating'] ?? 0)).'%' : '')->filter();
        if ($history->isNotEmpty()) {
            $lines[] = 'APPRAISALS READ (oldest first): '.$history->implode(', ').'.';
        }

        foreach (array_slice($score->warnings ?? [], 0, 3) as $warning) {
            $lines[] = 'NOTE FROM THE SYSTEM: '.$this->clean($warning, 200);
        }

        return [$lines, $reasons];
    }

    /**
     * A forecast has no points to share out, so its reasons are the facts the
     * forecast rests on: the latest rating, the history, and how sure it is.
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function performance(PerformanceForecast $forecast): array
    {
        /** @var PerformanceForecastRun $run */
        $run = $forecast->run;
        $reference = $run->reference ?? [];
        $band = (string) $forecast->band;
        $latest = $forecast->features['rating_latest'] ?? null;

        $lines = [
            'PREDICTION: Performance forecast — the rating (0–100) this employee’s next appraisal is likely to give'
                .($run->targetPeriod ? ' for "'.$this->clean($run->targetPeriod->name, 60).'"' : '').'. Bands: "Below target" under 60, "On track" 60–80, "Exceeds" 80 and up.',
            ...$this->model($run, 'appraisal histories of employees at other organisations'),
            'RESULT: '.PredictionWording::forecast($forecast).' — '.(PredictionWording::BAND_MEANING[$band] ?? '').'.',
        ];

        if ($forecast->predicted_low !== null && $forecast->predicted_high !== null) {
            $lines[] = 'RANGE: four in five next ratings like this land between '.PredictionWording::number((float) $forecast->predicted_low)
                .' and '.PredictionWording::number((float) $forecast->predicted_high).'.';
        }

        $lines[] = '';
        $lines[] = 'REASONS (what the forecast rests on):';
        $reasons = [];

        if ($latest !== null) {
            $drift = (float) $forecast->predicted_rating - (float) $latest;
            $lines[] = '[R1] Latest appraisal: '.PredictionWording::number((float) $latest).'%. Ratings like it were followed by '
                .PredictionWording::number((float) $forecast->predicted_rating).' on average ('.(abs($drift) < 0.5 ? 'about the same' : $this->signed(round($drift, 1)).' points').').';
            $reasons[] = ['key' => 'R1', 'label' => 'Latest appraisal', 'effect' => null, 'points' => null, 'fixed' => null];
        }

        $history = collect($forecast->history ?? [])->map(fn (mixed $h): string => is_array($h)
            ? $this->clean($h['label'] ?? 'Appraisal', 40).' '.PredictionWording::number((float) ($h['rating'] ?? 0)).'%' : '')->filter();
        if ($history->isNotEmpty()) {
            $key = 'R'.(count($reasons) + 1);
            $lines[] = "[{$key}] Appraisal history (oldest first): ".$history->implode(', ').'. The system reads the latest; the history shows whether it is typical for them.';
            $reasons[] = ['key' => $key, 'label' => 'Appraisal history', 'effect' => null, 'points' => null, 'fixed' => null];
        }

        $chances = $forecast->explanation['chances'] ?? null;
        if (is_array($chances)) {
            $key = 'R'.(count($reasons) + 1);
            $lines[] = "[{$key}] How sure it is: ".collect(PredictionWording::BANDS)
                ->map(fn (string $label, string $b): string => "{$label} ".PredictionWording::percent((float) ($chances[$b] ?? 0)))
                ->implode(', ').'.';
            $reasons[] = ['key' => $key, 'label' => 'How sure it is', 'effect' => null, 'points' => null, 'fixed' => null];
        }

        if (isset($reference['coverage'])) {
            $lines[] = 'TRACK RECORD OF THE SYSTEM: its ranges held '.PredictionWording::percent((float) $reference['coverage']).' of the ratings that followed, where they were checked.';
        }

        foreach (array_slice($forecast->warnings ?? [], 0, 3) as $warning) {
            $lines[] = 'NOTE FROM THE SYSTEM: '.$this->clean($warning, 200);
        }

        return [$lines, $reasons];
    }

    // ── Shared parts ─────────────────────────────────────────────────────────

    /**
     * Whose model scored the run, when, and — for the organisation's own — whether it
     * passed its check (ADR 0107).
     *
     * @return list<string>
     */
    private function model(Model $run, string $general): array
    {
        /** @var LocalModel|null $own */
        $own = $run->local_model_id !== null ? $run->localModel : null;

        return [
            'SCORED BY: '.match (true) {
                $own === null && $run->local_model_id !== null => "the organisation's own model (since set aside).",
                $own === null => "the general model, learned from {$general} — not from this organisation's people.",
                (bool) $own->passed => "the organisation's own model, trained on its own records; it passed a check against the general model on the organisation's people.",
                default => "the organisation's own model, trained on its own records. It did NOT pass its check against the general model, and the organisation chose it anyway.",
            },
            'RUN DATE: '.($run->created_at?->toDateString() ?? 'unknown').' (today is '.now()->toDateString().').',
        ];
    }

    /**
     * The record beside the prediction, as far as the viewer may see it. A block they
     * may not see is named as unavailable, so its absence is never read as "none".
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function record(string $surface, User $viewer, array $context): array
    {
        $lines = ['THE RECORD BESIDE IT (what this HR officer may see):'];
        $employment = $context['employment'] ?? [];

        $lines[] = 'EMPLOYMENT: '.collect([
            filled($employment['position'] ?? null) ? 'position "'.$this->clean($employment['position'], 80).'"' : null,
            filled($employment['department'] ?? null) ? 'department "'.$this->clean($employment['department'], 80).'"' : null,
            isset($employment['tenure_years']) ? 'tenure '.PredictionWording::number((float) $employment['tenure_years']).' years' : null,
            filled($employment['employment_type'] ?? null) ? 'employment type '.$this->clean($employment['employment_type'], 30) : null,
        ])->filter()->implode(', ').'.';

        $history = collect($context['history'] ?? [])->map(fn (array $h): string => substr((string) ($h['at'] ?? ''), 0, 10).': '
            .PredictionWording::number((float) $h['score']).($surface === 'performance' && isset($h['actual']) ? ' (actual rating '.PredictionWording::number((float) $h['actual']).')' : ''));
        if ($history->count() > 1) {
            $lines[] = 'TREND (this prediction in each run, oldest first): '.$history->implode('; ').'.';
        }

        $signals = collect($context['signals'] ?? [])->map(fn (?array $signal, string $other): string => self::SURFACE_TITLES[$other].': '.($signal === null
            ? 'not assessed yet'
            : PredictionWording::number((float) $signal['score']).' ('.$this->tierLabel($other, (string) $signal['tier']).')'));
        if ($signals->isNotEmpty()) {
            $lines[] = 'THEIR OTHER PREDICTIONS: '.$signals->implode('; ').'.';
        }

        $unavailable = collect(PredictionContext::RECORD_GATES)->reject(fn (string $permission): bool => $viewer->can($permission))->keys();

        if ($viewer->can(PredictionContext::RECORD_GATES['offboarding'])) {
            $case = $context['offboarding'] ?? null;
            $lines[] = 'OFFBOARDING: '.($case === null ? 'no case in progress.' : $this->clean($case['type'], 30).' case, '.$this->clean($case['status'], 20)
                .($case['last_working_day'] ? ', last working day '.$case['last_working_day'] : '').'.');
        }

        if (is_array($context['attendance'] ?? null)) {
            $months = collect($context['attendance']['months'] ?? [])->filter(fn (array $m): bool => $m['tracked'] > 0);
            $lines[] = 'ATTENDANCE (last 6 months): '.($months->isEmpty() ? 'nothing tracked.' : $months->map(fn (array $m): string => "{$m['label']} {$m['worked']} worked, {$m['absences']} absent, {$m['lates']} late, {$m['overtime_hours']} h overtime")->implode('; ').'.');
        }

        if (is_array($context['leave'] ?? null)) {
            $leave = $context['leave'];
            $lines[] = 'LEAVE: '.PredictionWording::number((float) $leave['days_12m']).' days of approved leave taken in the last 12 months; '
                .$leave['pending'].' request(s) pending; '.count($leave['upcoming'] ?? []).' booked ahead.';
        }

        if (is_array($context['appraisals'] ?? null)) {
            $lines[] = 'APPRAISALS (oldest first): '.(collect($context['appraisals'])->map(fn (array $a): string => $this->clean($a['period'] ?? 'Appraisal', 40).' '
                .PredictionWording::number((float) $a['percent']).'%'.(filled($a['result'] ?? null) ? ' "'.$this->clean($a['result'], 30).'"' : '')
                .(($a['sections'] ?? []) !== [] ? ' ['.collect($a['sections'])->map(fn (array $s): string => $this->clean($s['name'], 40).' '.PredictionWording::number((float) $s['percent']).'%')->implode(', ').']' : ''))
                ->implode('; ') ?: 'none completed').'.';
        }

        if (is_array($context['promotions'] ?? null)) {
            $lines[] = 'PROMOTIONS HERE: '.(collect($context['promotions'])->map(fn (array $p): string => ($p['effective_date'] ?? '?').' '
                .$this->clean($p['from'] ?? '—', 60).' → '.$this->clean($p['to'] ?? '—', 60))->implode('; ') ?: 'none').'.';
        }

        if (is_array($context['training'] ?? null)) {
            $training = $context['training'];
            $lines[] = 'TRAINING: '.$training['completed_12m'].' completed in the last 12 months'
                .(($training['current'] ?? []) !== [] ? '; under way: '.collect($training['current'])->map(fn (array $t): string => '"'.$this->clean($t['program'], 60).'"')->implode(', ') : '').'.';
        }

        if (is_array($context['awards'] ?? null)) {
            $lines[] = 'AWARDS (last 2 years): '.(collect($context['awards'])->map(fn (array $a): string => '"'.$this->clean($a['type'], 60).'" '.($a['awarded_on'] ?? ''))->implode('; ') ?: 'none').'.';
        }

        if ($unavailable->isNotEmpty()) {
            $lines[] = 'NOT AVAILABLE TO THIS VIEWER (unknown — do not assume anything): '.$unavailable->implode(', ').'.';
        }

        return $lines;
    }

    /**
     * The behavioural indicators of emotional states (ADR 0101) and the signals only
     * the organisation's records hold (ADR 0102) — except any read from pay.
     *
     * @param  array<int, mixed>  $readings
     * @param  array<int, mixed>  $definitions
     * @return list<string>
     */
    private function indicators(array $readings, array $definitions): array
    {
        $defined = collect($definitions)->filter(fn (mixed $d): bool => is_array($d) && isset($d['key']))->keyBy('key');

        $shown = collect($readings)
            ->filter(fn (mixed $r): bool => is_array($r) && isset($r['key'], $r['level']) && ! $this->touchesPay($r['inputs'] ?? []))
            ->map(function (array $r) use ($defined): string {
                $definition = $defined->get($r['key'], []);
                $label = $this->clean($definition['label'] ?? null, 60) ?: (string) $r['key'];
                $indicates = filled($definition['indicates'] ?? null) ? ' (read as '.$this->clean($definition['indicates'], 80).')' : '';
                $read = match ($r['read'] ?? 'none') {
                    'direct' => isset($r['points']) ? 'in the score, '.$this->signed((float) $r['points']).' points' : 'in the score',
                    'inputs' => 'reaches the score through its inputs',
                    default => 'not in the score',
                };

                return "{$label}{$indicates}: ".$this->clean((string) $r['level'], 40)." — {$read}";
            })
            ->values();

        return $shown->isEmpty() ? [] : [
            'BEHAVIOURAL INDICATORS (behaviour research reads as a sign of how someone feels; they do not measure feelings): '.$shown->implode('; ').'.',
        ];
    }

    /**
     * What the data the model learned from shows for one attrition factor's band.
     *
     * @param  array<string, mixed>  $factor
     */
    private function evidence(array $factor): string
    {
        $e = $factor['evidence'] ?? null;

        if (! is_array($e) || ! isset($e['rate'], $factor['band'])) {
            return '';
        }

        $typical = isset($e['typical_rate'], $factor['typical_band'])
            ? ', against '.PredictionWording::percent((float) $e['typical_rate']).' at "'.$this->clean($factor['typical_band'], 40).'"'
            : '';
        $agrees = ($e['consistent'] ?? null) === false
            ? ' On its own that data points the other way; the system weighs this input together with the others.'
            : '';

        return ' In the data learned from, '.PredictionWording::percent((float) $e['rate']).' of the '.(int) ($e['people'] ?? 0).' people at "'
            .$this->clean($factor['band'], 40).'" left'.$typical.'.'.$agrees;
    }

    /**
     * One attrition input's value in words ("2.2 yrs", "Never"), or an indicator's
     * level with the inputs it was read from.
     *
     * @param  array<string, mixed>|null  $components
     */
    private function attritionValue(string $feature, mixed $value, ?array $components = null): string
    {
        $line = PredictionWording::attritionInput($feature, $value);
        $shown = $line !== null ? substr($line, strpos($line, ': ') + 2) : $this->clean(is_scalar($value) ? (string) $value : '', 40);

        if (is_array($components) && $components !== []) {
            $parts = collect($components)
                ->map(fn (mixed $v, string $k): ?string => PredictionWording::attritionInput($k, $v))
                ->filter();
            $shown .= $parts->isNotEmpty() ? ' ('.$parts->implode(', ').')' : '';
        }

        return $shown;
    }

    private function isPay(string $feature, mixed $group): bool
    {
        return in_array($feature, PredictionWording::WITHHELD_INPUTS, true) || $group === 'compensation';
    }

    /**
     * @param  mixed  $inputs  an input => value map
     */
    private function touchesPay(mixed $inputs): bool
    {
        return is_array($inputs) && array_intersect(array_keys($inputs), PredictionWording::WITHHELD_INPUTS) !== [];
    }

    private function tierLabel(string $surface, string $tier): string
    {
        return match ($surface) {
            'attrition' => PredictionWording::RISK_TIERS[$tier] ?? $tier,
            'promotion' => (PredictionWording::READINESS_TIERS[$tier] ?? $tier).' readiness',
            default => PredictionWording::BANDS[$tier] ?? $tier,
        };
    }

    /** "+4.2" / "−3.1" (a true minus sign, as the pages print it). */
    private function signed(float $value): string
    {
        return ($value >= 0 ? '+' : '−').PredictionWording::number(abs($value));
    }

    /** A rating change is signed; a rating is not. */
    private function signedIf(string $feature, float $value): string
    {
        return $feature === 'rating_change' ? $this->signed($value) : PredictionWording::number($value);
    }

    private function clean(mixed $value, int $limit): string
    {
        return UntrustedText::clean(is_scalar($value) ? (string) $value : null, $limit) ?? '';
    }
}
