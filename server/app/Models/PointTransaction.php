<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Recognition\PointsLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the points ledger (ADR 0071): a signed amount, what kind of
 * thing caused it, and the record that did. Lines are only ever added — a
 * balance is their sum ({@see PointsLedger}).
 */
class PointTransaction extends Model
{
    use BelongsToOrganization;

    public const KINDS = ['award', 'kudos', 'redemption', 'refund', 'adjustment'];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'amount',
        'kind',
        'subject_type',
        'subject_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
