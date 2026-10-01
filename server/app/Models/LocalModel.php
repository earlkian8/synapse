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
 * `failed`: the check did not pass and nothing was stored. `ready`: it passed and can
 * be switched to. `active`: it scores the surface (at most one per surface).
 * `retired`: switched away from, kept so historical runs still say whose model
 * scored them. Transitions go through {@see ModelGraduation}.
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
        'version',
        'examples',
        'counts',
        'comparison',
        'findings',
        'trained_by',
        'activated_by',
        'activated_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'examples' => 'integer',
            'counts' => 'array',
            'comparison' => 'array',
            'findings' => 'array',
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
