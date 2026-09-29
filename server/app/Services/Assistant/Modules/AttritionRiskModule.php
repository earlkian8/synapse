<?php

namespace App\Services\Assistant\Modules;

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\User;
use App\Support\Ml\AttritionRiskAssessor;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlClient;
use App\Support\Ml\PredictionWording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Attrition Risk capability (ADR 0058): how likely each active employee is to
 * resign, as the latest assessment scored it — "who is at risk of leaving in
 * Sales?", "why is Maria at watch?", "run a new attrition assessment".
 *
 * Runs through {@see AttritionRiskAssessor}, the screen's own path; everything
 * shared with the other predictive surfaces is {@see PredictiveModule}.
 *
 * **Pay is never discussed.** The model takes it, and the page shows it to
 * those who may see the page, but the assistant withholds pay from everybody
 * (ADR 0027): its value is never sent, and when pay moved a score, the factor is
 * left out and the card says only that pay is one of the inputs.
 */
class AttritionRiskModule extends PredictiveModule
{
    /** How many factors a read names at most. */
    private const MAX_FACTORS = 5;

    public function __construct(ModelGraduation $graduation, MlClient $ml, private readonly AttritionRiskAssessor $assessor)
    {
        parent::__construct($graduation, $ml);
    }

    public function key(): string
    {
        return 'attrition-risk';
    }

    protected function surface(): string
    {
        return 'attrition';
    }

    protected function noun(): string
    {
        return 'assessment';
    }

    protected function subject(): string
    {
        return 'attrition risk';
    }

    protected function names(): array
    {
        return [
            'summary' => 'attrition_risk_summary',
            'find' => 'find_attrition_risks',
            'get' => 'get_attrition_risk',
            'status' => 'get_attrition_model_status',
            'run' => 'run_attrition_assessment',
            'delete' => 'delete_attrition_assessment',
            'train' => 'train_attrition_model',
            'switch' => 'switch_attrition_model',
        ];
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        ATTRITION RISK — how likely each active employee is to resign, as the latest assessment scored it (0–100; tiers High risk, At watch, Stable). The model reads what the ERP records: employment type, tenure, time since last promotion, the last 90 days' absences, late arrivals and overtime, and pay. Confidence is the share of those inputs on record.
        - attrition_risk_summary for the picture; find_attrition_risks for who (by tier, department, name); get_attrition_risk for one person and what moves their score. Modelling status, training the organisation's own model and switching models have their own tools; deleting an assessment and switching the model wait for the user's confirmation.
        - A score is a statistical signal to prompt a supportive check-in. Never suggest discipline, dismissal or holding someone back because of it, never suggest telling anyone their score, and do not guess at reasons beyond the factors given. Pay is one of the inputs but is never discussed here.
        TXT;
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['attrition', 'attrition risk', 'flight risk', 'at risk of leaving', 'likely to leave', 'likely to resign', 'retention risk', 'turnover risk'];
    }

    protected function runs(): Builder
    {
        return AttritionRiskRun::query();
    }

    protected function scoresOf(Model $run): HasMany
    {
        /** @var AttritionRiskRun $run */
        return $run->scores();
    }

    protected function tierColumn(): string
    {
        return 'tier';
    }

    protected function tiers(): array
    {
        return PredictionWording::RISK_TIERS;
    }

    protected function counts(Model $run): array
    {
        return ['High risk' => (int) $run->high_count, 'At watch' => (int) $run->medium_count, 'Stable' => (int) $run->low_count];
    }

    protected function headline(Model $run): array
    {
        return array_values(array_filter([
            $run->average_score !== null ? 'Average risk '.PredictionWording::score((float) $run->average_score).'/100' : null,
            $run->average_confidence !== null ? 'Average confidence '.PredictionWording::percent((float) $run->average_confidence) : null,
        ]));
    }

    protected function rowMeta(Model $score, Model $run): array
    {
        /** @var AttritionRiskScore $score */
        $raising = collect($this->factors($score))->firstWhere('direction', 'up');

        return [
            'Risk '.PredictionWording::score((float) $score->score).'/100',
            $raising !== null ? "Most raised by: {$raising['label']}" : null,
            'Confidence '.PredictionWording::percent((float) $score->confidence),
        ];
    }

    protected function detail(Model $score, Model $run, ?Model $previous): array
    {
        /** @var AttritionRiskScore $score */
        $features = $score->features ?? [];
        $factors = $this->factors($score);
        $recorded = collect(AttritionRiskAssessor::KEY_FEATURES)->filter(fn (string $key): bool => array_key_exists($key, $features));
        $missing = collect(AttritionRiskAssessor::KEY_FEATURES)
            ->reject(fn (string $key): bool => array_key_exists($key, $features) || in_array($key, PredictionWording::WITHHELD_INPUTS, true))
            ->map(fn (string $key): ?string => PredictionWording::attritionFactor($key))
            ->filter();
        $payMoved = collect($score->factors ?? [])->contains(fn (mixed $f): bool => is_array($f) && in_array($f['feature'] ?? null, PredictionWording::WITHHELD_INPUTS, true));

        return [
            'Risk '.PredictionWording::score((float) $score->score).'/100 — '.(PredictionWording::RISK_TIERS[$score->tier] ?? $score->tier).': '.(PredictionWording::RISK_MEANING[$score->tier] ?? ''),
            'Confidence '.PredictionWording::percent((float) $score->confidence).' — '.$recorded->count().' of '.count(AttritionRiskAssessor::KEY_FEATURES).' inputs on record',
            $factors === [] ? 'The model did not attribute this score to its inputs' : 'What moves it: '.collect($factors)->map(fn (array $f): string => $f['label'].($f['direction'] === 'up' ? ' raises it' : ' lowers it'))->implode('; '),
            $payMoved || array_key_exists('monthly_salary', $features) ? 'Pay is also one of its inputs; it is not discussed here.' : null,
            'Based on: '.(collect($features)->map(fn (mixed $value, string $key): ?string => PredictionWording::attritionInput($key, $value))->filter()->implode('; ') ?: 'nothing on record'),
            $missing->isNotEmpty() ? 'Not on record (estimated): '.$missing->implode(', ') : null,
            $previous !== null ? 'Previous assessment: '.PredictionWording::score((float) $previous->score).'/100 ('.(PredictionWording::RISK_TIERS[$previous->tier] ?? $previous->tier).')' : null,
        ];
    }

    protected function execute(User $user): Model
    {
        return $this->assessor->run($user, self::CHANNEL);
    }

    protected function forget(Model $run): void
    {
        /** @var AttritionRiskRun $run */
        $this->assessor->delete($run, self::CHANNEL);
    }

    /**
     * What moved a score, strongest first, without pay.
     *
     * @return list<array{label: string, direction: string}>
     */
    private function factors(AttritionRiskScore $score): array
    {
        return collect($score->factors ?? [])
            ->filter(fn (mixed $f): bool => is_array($f) && ! in_array($f['feature'] ?? null, PredictionWording::WITHHELD_INPUTS, true))
            ->sortByDesc(fn (array $f): float => abs((float) ($f['impact'] ?? 0)))
            ->take(self::MAX_FACTORS)
            ->map(fn (array $f): array => [
                'label' => PredictionWording::attritionFactor((string) ($f['feature'] ?? '')) ?? $this->label($f['label'] ?? null),
                'direction' => ($f['direction'] ?? 'up') === 'down' ? 'down' : 'up',
            ])
            ->values()
            ->all();
    }
}
