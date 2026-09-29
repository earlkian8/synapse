<?php

namespace App\Support\Ml\Graduation;

use App\Models\LocalModel;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ml\MlClient;
use App\Support\Ml\MlException;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * The canonical operation behind model graduation (ADR 0046) for all three
 * predictive surfaces: where each stands, training a model on the organisation's own
 * records, switching the surface to it, and switching back.
 *
 * The lifecycle, per surface:
 *
 *  - **provisional** — scored by the general model; nothing the organisation's own
 *    model would learn from has been recorded yet;
 *  - **collecting** — still the general model, while the organisation's own history
 *    accumulates towards the requirements;
 *  - **graduated** — scored by a model trained on the organisation's own records,
 *    which passed its check and was switched to by someone allowed to manage the
 *    surface.
 *
 * Training is allowed only when every requirement is met, and what the requirements
 * count is exactly what is sent ({@see TrainingSet}). The inference service fits the
 * model, checks it against the general model on the organisation's own people and
 * stores it only when it passes; nothing changes for anyone until someone switches.
 */
class ModelGraduation
{
    /** @var array<string, array{surface: class-string<GraduationSurface>, title: string, log: string}> */
    public const SURFACES = [
        'promotion' => ['surface' => PromotionGraduation::class, 'title' => 'Promotion Readiness', 'log' => 'promotion-readiness'],
        'performance' => ['surface' => PerformanceGraduation::class, 'title' => 'Performance Forecast', 'log' => 'performance-forecast'],
        'attrition' => ['surface' => AttritionGraduation::class, 'title' => 'Attrition Risk', 'log' => 'attrition-risk'],
    ];

    public function __construct(
        private readonly MlClient $ml,
        private readonly Tenancy $tenancy,
    ) {}

    public function surface(string $model): GraduationSurface
    {
        return app(self::SURFACES[$model]['surface']);
    }

    /**
     * Where `$model` stands: its stage, every requirement, the organisation's own
     * models, and the field coverage behind its scores. What the panel renders.
     *
     * @param  bool  $serviceReady  whether the inference service is up with `$model` loaded
     * @return array<string, mixed>
     */
    public function check(string $model, bool $serviceReady): array
    {
        $surface = $this->surface($model);
        $set = $surface->trainingSet();
        $counts = new FieldCounts;
        $requirements = [...$surface->requirements($set, $counts), $this->serviceRequirement($serviceReady)];

        $active = LocalModel::activeFor($model);
        $latest = LocalModel::query()->forModel($model)->whereIn('status', ['ready', 'failed'])->latest('id')->first();

        return [
            'model' => $model,
            'stage' => match (true) {
                $active !== null => 'graduated',
                $surface->started($set) => 'collecting',
                default => 'provisional',
            },
            'gate_open' => collect($requirements)->every(fn (Requirement $r): bool => $r->met()),
            'requirements' => array_map(fn (Requirement $r): array => $r->toArray(), $requirements),
            'met_count' => collect($requirements)->filter(fn (Requirement $r): bool => $r->met())->count(),
            'total_count' => count($requirements),
            'binding_key' => $this->binding($requirements)?->key,
            'examples' => count($set->rows),
            'active' => $active ? $this->summary($active) : null,
            // The newest attempt that is not the one in use: a model ready to switch
            // to, or a check that did not pass.
            'latest' => $latest ? $this->summary($latest) : null,
            'fields' => $surface->fields($counts),
            'employees' => $counts->active(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Train `$model` on the organisation's own records and record the attempt. A
     * model that passes its check is stored as `ready` (superseding any earlier one
     * not in use); one that fails is recorded with the reason.
     *
     * @throws GraduationException when a requirement is unmet.
     * @throws MlException when the inference service cannot train.
     */
    public function train(string $model, ?User $actor, string $channel = ''): LocalModel
    {
        $surface = $this->surface($model);
        $set = $surface->trainingSet();
        $unmet = collect($surface->requirements($set, new FieldCounts))->reject(fn (Requirement $r): bool => $r->met());

        if ($unmet->isNotEmpty()) {
            throw new GraduationException('Not every requirement is met yet — still to go: '.$unmet->map(fn (Requirement $r): string => lcfirst($r->label))->join(', ', ' and ').'.');
        }

        $response = $this->ml->train($model, LocalModel::tenantKey($this->tenancy->id()), $set->rows);
        $passed = ($response['verdict'] ?? null) === 'passed' && ! empty($response['version']);

        $attempt = DB::transaction(function () use ($model, $response, $passed, $set, $actor): LocalModel {
            if ($passed) {
                // One model is offered at a time: the newest that passed.
                LocalModel::query()->forModel($model)->where('status', 'ready')
                    ->update(['status' => 'retired', 'retired_at' => now()]);
            }

            return LocalModel::create([
                'model' => $model,
                'status' => $passed ? 'ready' : 'failed',
                'version' => $passed ? $response['version'] : null,
                'examples' => count($set->rows),
                'counts' => $response['counts'] ?? null,
                'comparison' => $response['comparison'] ?? null,
                'findings' => $response['findings'] ?? [],
                'trained_by' => $actor?->id,
            ]);
        });

        $title = self::SURFACES[$model]['title'];

        ActivityLogger::log(
            event: 'trained',
            description: "Trained a {$title} model on the organisation's own records ({$attempt->examples} examples) — "
                .($passed ? 'it passed its check' : 'it did not pass its check').$channel,
            subject: $attempt,
            logName: self::SURFACES[$model]['log'],
        );

        return $attempt;
    }

    /**
     * Switch the surface to `$candidate`, retiring whichever of the organisation's
     * models it replaces. From the next run on, the surface is scored by it.
     *
     * @throws GraduationException when it did not pass its check or is already in use.
     */
    public function activate(LocalModel $candidate, ?User $actor, string $channel = ''): void
    {
        if ($candidate->status !== 'ready') {
            throw new GraduationException($candidate->status === 'active'
                ? 'This model is already in use.'
                : 'Only a model that passed its check, and is the newest to have done so, can be switched to.');
        }

        DB::transaction(function () use ($candidate, $actor): void {
            LocalModel::query()->forModel($candidate->model)->where('status', 'active')->lockForUpdate()
                ->update(['status' => 'retired', 'retired_at' => now()]);

            $candidate->update(['status' => 'active', 'activated_by' => $actor?->id, 'activated_at' => now()]);
        });

        ActivityLogger::log(
            event: 'activated',
            description: 'Switched '.self::SURFACES[$candidate->model]['title']." to the organisation's own model{$channel}",
            subject: $candidate,
            logName: self::SURFACES[$candidate->model]['log'],
        );
    }

    /**
     * Switch the surface back to the general model. The organisation's model is
     * retired, not deleted: runs it scored still say so.
     *
     * @throws GraduationException when the surface already uses the general model.
     */
    public function revert(string $model, ?User $actor, string $channel = ''): LocalModel
    {
        $active = LocalModel::activeFor($model);

        if ($active === null) {
            throw new GraduationException('This page already uses the general model.');
        }

        $active->update(['status' => 'retired', 'retired_at' => now()]);

        ActivityLogger::log(
            event: 'retired',
            description: 'Switched '.self::SURFACES[$model]['title'].' back to the general model'.$channel,
            subject: $active,
            logName: self::SURFACES[$model]['log'],
        );

        return $active;
    }

    /**
     * The one requirement all three share: training runs on the inference service,
     * which also runs the general model the new one is checked against.
     */
    private function serviceRequirement(bool $ready): Requirement
    {
        return new Requirement(
            key: 'service',
            label: 'Prediction service ready',
            group: 'system',
            format: 'check',
            current: $ready ? 1 : 0,
            required: 1,
            summary: 'Training runs on the prediction service, which also runs the general model to compare against.',
            action: 'If this isn’t met, ask your system administrator to start the prediction service.',
            basis: 'Your model is checked against the general model on your own records, so both have to be available.',
            source: 'Whether the prediction service is reachable with the general model for this page loaded.',
        );
    }

    /**
     * The unmet requirement furthest from ready among those that can be acted on
     * directly — derived ones rise on their own, so naming one would point at
     * something nobody can do.
     *
     * @param  list<Requirement>  $requirements
     */
    private function binding(array $requirements): ?Requirement
    {
        return collect($requirements)
            ->reject(fn (Requirement $r): bool => $r->met() || $r->derived || $r->group === 'system')
            ->sortBy(fn (Requirement $r): float => $r->progress())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(LocalModel $model): array
    {
        $model->loadMissing(['trainer:id,first_name,last_name', 'activator:id,first_name,last_name']);

        return [
            'hashid' => $model->hashid,
            'status' => $model->status,
            'examples' => $model->examples,
            'counts' => $model->counts ?? [],
            'comparison' => $model->comparison,
            'findings' => $model->findings ?? [],
            'trained_at' => $model->created_at?->toIso8601String(),
            'trained_by' => $model->trainer?->full_name,
            'activated_at' => $model->activated_at?->toIso8601String(),
            'activated_by' => $model->activator?->full_name,
        ];
    }
}
