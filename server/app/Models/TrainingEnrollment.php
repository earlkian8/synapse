<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One employee's enrollment in a {@see TrainingProgram}: their status, an optional
 * completion score + timestamp, and remarks. One row per employee per program.
 *
 * A request to join (ADR 0074) is a row too: `requested` until HR approves it
 * (`enrolled`) or declines it (`declined`, with a note). Neither takes a seat or
 * counts on the roster. Completing a program that issues certificates earns the
 * person a {@see EmployeeCertification} tied to this row (ADR 0075).
 */
class TrainingEnrollment extends Model
{
    use BelongsToOrganization;

    /** The lifecycle of an enrollment, a request to join included. */
    public const STATUSES = ['requested', 'enrolled', 'completed', 'dropped', 'declined'];

    /** The statuses of people on the roster — what HR grades. */
    public const ROSTER_STATUSES = ['enrolled', 'completed', 'dropped'];

    /** The statuses that hold a seat. */
    public const SEATED_STATUSES = ['enrolled', 'completed'];

    protected $fillable = [
        'organization_id',
        'training_program_id',
        'employee_id',
        'status',
        'score',
        'completed_at',
        'remarks',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'completed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TrainingProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<TrainingAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(TrainingAttendance::class);
    }

    /**
     * The certificate completing this program earned, if it issues one.
     *
     * @return HasOne<EmployeeCertification, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(EmployeeCertification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Limit to enrollments that occupy a seat (enrolled or completed — not
     * dropped, and not a request).
     *
     * @param  Builder<TrainingEnrollment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::SEATED_STATUSES);
    }

    /**
     * Limit to the people on the roster: not a request to join, nor a declined one.
     *
     * @param  Builder<TrainingEnrollment>  $query
     */
    public function scopeOnRoster(Builder $query): void
    {
        $query->whereIn('status', self::ROSTER_STATUSES);
    }

    /**
     * Limit to requests to join that wait for a decision.
     *
     * @param  Builder<TrainingEnrollment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'requested');
    }

    public function isOnRoster(): bool
    {
        return in_array($this->status, self::ROSTER_STATUSES, true);
    }
}
