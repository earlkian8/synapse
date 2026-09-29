<?php

namespace App\Services\Assistant\Modules;

use App\Models\PromotionReadinessRun;
use App\Models\PromotionReadinessScore;
use App\Models\User;
use App\Services\Assistant\Security\UntrustedText;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlClient;
use App\Support\Ml\PredictionWording;
use App\Support\Ml\PromotionReadinessAssessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promotion Readiness capability (ADR 0058): how each assessed employee's
 * appraisal record compares with the records of people who were promoted —
 * "who is ready for promotion in Operations?", "how ready is Jon?", "who did
 * the assessment leave out?".
 *
 * Runs through {@see PromotionReadinessAssessor}, the screen's own path;
 * everything shared with the other predictive surfaces is
 * {@see PredictiveModule}. The odds are stated the way the page states them,
 * against the right "average": the organisation's own promotion rate when its
 * own model scored the run, else the reference workforce's
 * ({@see PromotionReadinessRun::baseRate()}).
 */
class PromotionReadinessModule extends PredictiveModule
{
    /** What each input the model reads is called. */
    private const FACTORS = ['rating_latest' => 'Latest appraisal', 'rating_change' => 'Change since the previous appraisal'];

    public function __construct(ModelGraduation $graduation, MlClient $ml, private readonly PromotionReadinessAssessor $assessor)
    {
        parent::__construct($graduation, $ml);
    }

    public function key(): string
    {
        return 'promotion-readiness';
    }

    protected function surface(): string
    {
        return 'promotion';
    }

    protected function noun(): string
    {
        return 'assessment';
    }

    protected function subject(): string
    {
        return 'promotion readiness';
    }

    protected function declines(): bool
    {
        return true;
    }

    protected function names(): array
    {
        return [
            'summary' => 'promotion_readiness_summary',
            'find' => 'find_promotion_readiness',
            'get' => 'get_promotion_readiness',
            'status' => 'get_promotion_model_status',
            'run' => 'run_promotion_assessment',
            'delete' => 'delete_promotion_assessment',
            'train' => 'train_promotion_model',
            'switch' => 'switch_promotion_model',
        ];
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        PROMOTION READINESS — how each employee's appraisal record compares with the records of people who were promoted (0–100; tiers High, Medium, Low). It reads only completed appraisals: the latest rating and, when there is one, the change since the previous. Nothing else — not department, pay, tenure or anything personal. People with no completed appraisal are left out, with the reason.
        - promotion_readiness_summary for the picture; find_promotion_readiness for who (by tier, department, name, or declined: true for who was left out); get_promotion_readiness for one person, their odds and what they rest on. Deleting an assessment and switching the model wait for the user's confirmation.
        - Readiness is one input to a human decision about a person, never the decision. Say what the record shows; do not promise or rule out a promotion.
        TXT;
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['promotion readiness', 'ready for promotion', 'ready to be promoted', 'promotion ready', 'promotion-ready', 'succession', 'who should we promote', 'promotable'];
    }

    protected function runs(): Builder
    {
        return PromotionReadinessRun::query();
    }

    protected function scoresOf(Model $run): HasMany
    {
        /** @var PromotionReadinessRun $run */
        return $run->scores();
    }

    protected function tierColumn(): string
    {
        return 'tier';
    }

    protected function tiers(): array
    {
        return PredictionWording::READINESS_TIERS;
    }

    protected function counts(Model $run): array
    {
        return ['High' => (int) $run->high_count, 'Medium' => (int) $run->medium_count, 'Low' => (int) $run->low_count];
    }

    protected function headline(Model $run): array
    {
        return $run->average_score !== null ? ['Average readiness '.PredictionWording::score((float) $run->average_score).'/100'] : [];
    }

    protected function rowMeta(Model $score, Model $run): array
    {
        /** @var PromotionReadinessScore $score */
        $strongest = $this->factors($score)[0] ?? null;

        return [
            'Readiness '.PredictionWording::score((float) $score->score).'/100',
            $this->basis($score),
            $strongest,
        ];
    }

    protected function detail(Model $score, Model $run, ?Model $previous): array
    {
        /** @var PromotionReadinessScore $score */
        /** @var PromotionReadinessRun $run */
        $own = $run->local_model_id !== null && $run->baseRate() !== null;
        $average = $own ? (float) $run->baseRate() : PromotionReadinessRun::REFERENCE_RATE;
        $lift = $average > 0 ? round((float) $score->probability / $average, 1) : null;
        $factors = $this->factors($score);

        return [
            'Readiness '.PredictionWording::score((float) $score->score).'/100 — '.(PredictionWording::READINESS_TIERS[$score->tier] ?? $score->tier).': '.(PredictionWording::READINESS_MEANING[$score->tier] ?? ''),
            'Of people with this record '.($own ? "in your organisation's own history" : 'in the reference workforce').', '.PredictionWording::percent((float) $score->probability)
                .($own ? ' were promoted before their next appraisal, or within a year' : ' were promoted within a year')
                .($lift !== null ? " — {$lift}× the average of ".PredictionWording::percent($average) : ''),
            $this->basis($score),
            $factors !== [] ? 'What moves it: '.implode('; ', $factors) : null,
            ($score->history ?? []) !== [] ? 'Appraisals: '.collect($score->history)->map(fn (array $a): string => (UntrustedText::clean($a['label'] ?? null, 60) ?? 'Appraisal').' '.PredictionWording::number((float) ($a['rating'] ?? 0)).'%')->implode(', ') : null,
            ...collect($score->warnings ?? [])->take(2)->map(fn (mixed $w): ?string => UntrustedText::clean(is_scalar($w) ? (string) $w : null, 200))->all(),
            $previous !== null ? 'Previous assessment: '.PredictionWording::score((float) $previous->score).'/100 ('.(PredictionWording::READINESS_TIERS[$previous->tier] ?? $previous->tier).')' : null,
        ];
    }

    protected function execute(User $user): Model
    {
        return $this->assessor->run($user, self::CHANNEL);
    }

    protected function forget(Model $run): void
    {
        /** @var PromotionReadinessRun $run */
        $this->assessor->delete($run, self::CHANNEL);
    }

    private function basis(PromotionReadinessScore $score): ?string
    {
        return match ($score->basis) {
            'two_appraisals' => 'Rests on two appraisals',
            'latest_appraisal' => 'Rests on one appraisal — a second will firm it up',
            default => null,
        };
    }

    /**
     * What moved a score, in readiness points, strongest first: "Latest appraisal +12 points".
     *
     * @return list<string>
     */
    private function factors(PromotionReadinessScore $score): array
    {
        return collect($score->factors ?? [])
            ->filter(fn (mixed $f): bool => is_array($f))
            ->sortByDesc(fn (array $f): float => abs((float) ($f['impact'] ?? 0)))
            ->map(fn (array $f): string => (self::FACTORS[$f['feature'] ?? ''] ?? $this->label($f['label'] ?? null)).' '
                .(($f['direction'] ?? 'up') === 'down' ? '−' : '+').PredictionWording::number(abs((float) ($f['impact'] ?? 0))).' points')
            ->values()
            ->all();
    }
}
