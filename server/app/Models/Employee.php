<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birth_date',
        'gender',
        'civil_status',
        'email',
        'phone',
        'address',
        'photo',
        'department_id',
        'position_id',
        'manager_id',
        'work_schedule_id',
        'employment_type',
        'employment_status',
        'date_hired',
        'date_regularized',
        'basic_salary',
        'bank_name',
        'bank_account_no',
        'tin',
        'sss_no',
        'philhealth_no',
        'pagibig_no',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'date_hired' => 'date',
            'date_regularized' => 'date',
            'basic_salary' => 'decimal:2',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The auth account linked to this employee, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo<WorkSchedule, $this>
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * This employee's direct manager.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Employees who report to this one.
     *
     * @return HasMany<Employee, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * @return HasMany<EmployeeCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }

    /**
     * @return HasMany<EmployeePromotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(EmployeePromotion::class);
    }

    /**
     * This employee's onboarding journey, if one has been started.
     *
     * @return HasOne<OnboardingCase, $this>
     */
    public function onboardingCase(): HasOne
    {
        return $this->hasOne(OnboardingCase::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim(implode(' ', array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
                $this->suffix,
            ]))),
        );
    }

    /**
     * Build the employee's initials from their first and last name.
     */
    public function initials(): string
    {
        return mb_strtoupper(
            mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)
        );
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across the searchable columns.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';

        $query->where(function (Builder $query) use ($needle) {
            foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email', 'phone'] as $column) {
                $query->orWhereRaw('lower('.$column.') like ?', [$needle]);
            }
        });
    }
}
