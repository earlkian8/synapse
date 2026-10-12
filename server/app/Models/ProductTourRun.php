<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\ProductTour;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the product tour (ADR 0080) — on the web or in the app, the first
 * one, a "What's new", or a replay: the stops it showed, how far it got and how
 * it ended. Recorded by {@see ProductTour}; read, per stop, on System → Product
 * tour. A run with no outcome long after it started was abandoned.
 */
class ProductTourRun extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    public const PLATFORMS = ['web', 'mobile'];

    /** @var list<string> */
    public const MODES = ['first', 'whats_new', 'replay'];

    protected $fillable = [
        'organization_id',
        'user_id',
        'platform',
        'version',
        'mode',
        'steps',
        'furthest_index',
        'last_step',
        'outcome',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'version' => 'integer',
            'furthest_index' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
