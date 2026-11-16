<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One incident about one employee, and the due process it is taken through
 * (ADR 0105) — the twin-notice rule of the Labor Code and DOLE Department Order
 * No. 147-15:
 *
 *  1. `reported` — what happened, by whom, where, who saw it.
 *  2. `notice_issued` — the **notice to explain**: the specific charge and the
 *     facts, with at least five calendar days to answer in writing.
 *  3. `explained` — their written explanation, and whether they asked for a
 *     hearing.
 *  4. `hearing` — a hearing or conference, when they asked for one or the
 *     facts are in dispute.
 *  5. `decided` — the **notice of decision**: every circumstance weighed, the
 *     charge upheld or not, and the penalty.
 *  6. `closed`, or `withdrawn` at any point before a decision.
 *
 * The employee's acknowledgement of each notice is kept as proof it was served.
 */
class DisciplinaryCase extends Model
{
    use BelongsToOrganization, HasHashid;

    public const STATUSES = ['reported', 'notice_issued', 'explained', 'hearing', 'decided', 'closed', 'withdrawn'];

    /** Still on its way to a decision. */
    public const OPEN = ['reported', 'notice_issued', 'explained', 'hearing'];

    /** The penalties a decision can carry, lightest first. */
    public const PENALTIES = [
        'verbal_warning' => 'Verbal reprimand',
        'written_warning' => 'Written warning',
        'final_warning' => 'Final written warning',
        'suspension' => 'Suspension without pay',
        'dismissal' => 'Dismissal',
    ];

    /** The fewest calendar days an employee is given to answer (D.O. 147-15). */
    public const MIN_REPLY_DAYS = 5;

    /** The longest a preventive suspension may run (Omnibus Rules, Book V). */
    public const MAX_PREVENTIVE_DAYS = 30;

    /** The longest a suspension as a penalty may run here. */
    public const MAX_SUSPENSION_DAYS = 30;

    protected $fillable = [
        'organization_id',
        'reference',
        'employee_id',
        'conduct_offense_id',
        'incident_date',
        'incident_time',
        'location',
        'narrative',
        'witnesses',
        'reported_by',
    ];

    protected $attributes = [
        'status' => 'reported',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'notice_issued_at' => 'datetime',
            'reply_due_on' => 'date',
            'notice_received_at' => 'datetime',
            'preventive_from' => 'date',
            'preventive_to' => 'date',
            'explained_at' => 'datetime',
            'hearing_requested' => 'boolean',
            'hearing_at' => 'datetime',
            'hearing_held_at' => 'datetime',
            'hearing_attended' => 'boolean',
            'occurrence' => 'integer',
            'suspension_days' => 'integer',
            'penalty_from' => 'date',
            'penalty_to' => 'date',
            'decided_at' => 'datetime',
            'decision_received_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ConductOffense, $this>
     */
    public function offense(): BelongsTo
    {
        return $this->belongsTo(ConductOffense::class, 'conduct_offense_id')->withTrashed();
    }

    /**
     * @return HasMany<DisciplinaryCaseAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(DisciplinaryCaseAttachment::class)->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function noticeIssuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notice_issued_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Cases the employee has been served — never one still at its report, which
     * is HR's until a notice is issued.
     *
     * @param  Builder<DisciplinaryCase>  $query
     */
    public function scopeServed(Builder $query): void
    {
        $query->whereNotNull('notice_issued_at');
    }

    /**
     * @param  Builder<DisciplinaryCase>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /**
     * Search by reference, or the employee's name or number — each word ANDed,
     * as everywhere else.
     *
     * @param  Builder<DisciplinaryCase>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        foreach (preg_split('/\s+/', $term) ?: [] as $word) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

            $query->where(fn (Builder $q) => $q
                ->where('reference', $like, $needle)
                ->orWhereHas('employee', fn (Builder $e) => $e
                    ->where('first_name', $like, $needle)
                    ->orWhere('last_name', $like, $needle)
                    ->orWhere('employee_no', $like, $needle)));
        }
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * Whether the time to answer the notice has run out without an answer —
     * the right to be heard in writing is then waived.
     */
    public function replyOverdue(string $today): bool
    {
        return $this->explained_at === null
            && $this->reply_due_on !== null
            && $this->reply_due_on->toDateString() < $today;
    }

    public function isUpheld(): bool
    {
        return $this->finding === 'upheld' && in_array($this->status, ['decided', 'closed'], true);
    }
}
