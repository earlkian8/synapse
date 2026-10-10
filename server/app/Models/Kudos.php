<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Recognition\KudosWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A thank-you from one employee to another (ADR 0071), on the recognition wall,
 * with the points it carried when it was sent. HR can take one down; its points
 * are reversed ({@see KudosWorkflow}).
 */
class Kudos extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'kudos';

    protected $fillable = [
        'organization_id',
        'from_employee_id',
        'to_employee_id',
        'message',
        'points',
    ];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /**
     * @return MorphMany<PointTransaction, $this>
     */
    public function pointTransactions(): MorphMany
    {
        return $this->morphMany(PointTransaction::class, 'subject');
    }
}
