<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Ml\Graduation\ModelGraduation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to train a predictive surface's model on this organisation's own
 * records ("model graduation", ADR 0046), and — when it passed its check — the
 * model the inference service stored for it.
 *
 * `status` is the lifecycle. `failed`: no model could be stored. `ready`: a model is
 * stored and can be switched to. `active`: it scores the surface (at most one per
 * surface). `retired`: switched away from or set aside, kept so historical runs still
 * say whose model scored them. Transitions go through {@see ModelGraduation}.
 *
 * `passed` is the check's verdict, kept apart from the lifecycle (ADR 0107): a model
 * that did not pass is stored too, and may be switched to knowingly by someone who
 * manages the surface. `interpretation` is the plain-language reading of the check
 * an LLM wrote for HR (ADR 0106).
 */
class LocalModel extends Model
{
    use BelongsToOrganization, HasHashid;

    /** The surfaces that can graduate — also the inference service's model names. */
    public const MODELS = ['promotion', 'performance', 'attrition'];

    public const STATUSES = ['failed', 'ready', 'active', 'retired'];

    protected $fillable = [
        'organization_id',
        'model',
        'status',
        'passed',
        'version',
        'examples',
        'counts',
        'comparison',
        'findings',
        'interpretation',
        'trained_by',
        'activated_by',
        'activated_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'examples' => 'integer',
            'counts' => 'array',
            'comparison' => 'array',
            'findings' => 'array',
            'interpretation' => 'array',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trained_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * The organisation's own model currently scoring `$model`, if it has one.
     */
    public static function activeFor(string $model): ?self
    {
        return self::query()->forModel($model)->where('status', 'active')->latest('id')->first();
    }

    /**
     * What the inference service is asked to score with: this organisation's model
     * at this version.
     *
     * @return array{tenant: string, version: string}
     */
    public function variant(): array
    {
        return ['tenant' => self::tenantKey($this->organization_id), 'version' => (string) $this->version];
    }

    /**
     * The key the inference service files an organisation's models under.
     */
    public static function tenantKey(int $organizationId): string
    {
        return "org-{$organizationId}";
    }

    /**
     * @param  Builder<LocalModel>  $query
     */
    public function scopeForModel(Builder $query, string $model): void
    {
        $query->where('model', $model);
    }
}
