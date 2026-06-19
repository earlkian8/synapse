<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashid;
use Database\Factories\OffboardingCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's exit — a {@see ClearanceItem} checklist with a deliberate
 * lifecycle (initiated → clearance → completed, or cancelled). Mirrors
 * {@see OnboardingCase}, but at the other end of the employment life cycle.
 * Started manually by HR for a departing employee. See ADR 0016.
 */
class OffboardingCase extends Model
{
    /** @use HasFactory<OffboardingCaseFactory> */
    use BelongsToOrganization, HasFactory, HasHashid;

    /**
     * The lifecycle states a case moves through.
     *
     * @var list<string>
     */
    public const STATUSES = ['initiated', 'clearance', 'completed', 'cancelled'];

    /**
     * The kinds of exit an offboarding may represent.
     *
     * @var list<string>
     */
    public const TYPES = ['resignation', 'termination', 'retirement', 'end_of_contract'];

    /**
     * The employment_status an employee lands in when an exit of each type is
     * finalised — the bridge back into the Employee core (ADR 0016).
     *
     * @var array<string, string>
     */
    public const EMPLOYMENT_STATUS_ON_COMPLETE = [
        'resignation' => 'resigned',
        'termination' => 'terminated',
        'retirement' => 'resigned',
        'end_of_contract' => 'resigned',
    ];

    protected $fillable = [
        'organization_id',
        'employee_id',
        'type',
        'notice_date',
        'last_working_day',
        'reason',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'last_working_day' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The clearance sign-offs, in display order.
     *
     * @return HasMany<ClearanceItem, $this>
     */
    public function clearanceItems(): HasMany
    {
        return $this->hasMany(ClearanceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether the exit is still being worked on.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['initiated', 'clearance'], true);
    }

    /**
     * The employment_status this exit transitions the employee to on completion.
     */
    public function targetEmploymentStatus(): string
    {
        return self::EMPLOYMENT_STATUS_ON_COMPLETE[$this->type] ?? 'resigned';
    }
}
