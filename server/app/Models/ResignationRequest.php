<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use App\Support\Offboarding\ResignationWorkflow;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Database\Factories\ResignationRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resignation the employee filed themselves (ADR 0076): the last day they
 * propose and why. It waits for HR, who accept it — it becomes the exit — or
 * decline it with a note; until then the employee may withdraw it. Every change
 * goes through {@see ResignationWorkflow}.
 */
class ResignationRequest extends Model
{
    /** @use HasFactory<ResignationRequestFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    /** @var list<string> */
    public const STATUSES = ['pending', 'accepted', 'declined', 'withdrawn'];

    /**
     * The notice the Labor Code asks of a resigning employee (Art. 300, formerly
     * 285): written, at least a month ahead. Shorter is HR's to agree to.
     */
    public const NOTICE_DAYS = 30;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'user_id',
        'proposed_last_day',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'offboarding_case_id',
    ];

    protected function casts(): array
    {
        return [
            'proposed_last_day' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<OffboardingCase, $this>
     */
    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class);
    }

    /**
     * @param  Builder<ResignationRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /**
     * Search by the employee who filed it — the columns the Employees list
     * searches. The reason is left out: it can be personal (ADR 0069).
     *
     * @param  Builder<ResignationRequest>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->whereHas('employee', fn (Builder $q) => $q->search($term));
        }
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Days between filing it and the last day it proposes — both days on the
     * organisation's calendar, so a resignation filed just after midnight in
     * Manila counts from that day, not from the day before in UTC.
     */
    public function noticeDays(): int
    {
        $filed = CarbonImmutable::parse(OrganizationClock::localDate(CarbonImmutable::instance($this->created_at ?? now())));

        return (int) $filed->diffInDays(CarbonImmutable::parse($this->proposed_last_day->toDateString()), false);
    }
}
