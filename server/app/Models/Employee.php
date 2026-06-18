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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * This employee's recurring allowances (drive payslip earning lines).
     *
     * @return HasMany<EmployeeAllowance, $this>
     */
    public function allowances(): HasMany
    {
        return $this->hasMany(EmployeeAllowance::class);
    }

    /**
     * This employee's recurring deductions, e.g. loans (drive payslip deduction
     * lines on top of the mandatory statutory ones).
     *
     * @return HasMany<EmployeeDeduction, $this>
     */
    public function recurringDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    /**
     * This employee's benefit-plan enrollments (HMO, insurance, …).
     *
     * @return HasMany<BenefitEnrollment, $this>
     */
    public function benefitEnrollments(): HasMany
    {
        return $this->hasMany(BenefitEnrollment::class);
    }

    /**
     * This employee's statutory contributions (SSS / PhilHealth / Pag-IBIG),
     * derived from processed payroll runs.
     *
     * @return HasMany<BenefitContribution, $this>
     */
    public function benefitContributions(): HasMany
    {
        return $this->hasMany(BenefitContribution::class);
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

    /**
     * This employee's filed leave requests.
     *
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * This employee's per-type, per-year leave allocations.
     *
     * @return HasMany<LeaveBalance, $this>
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /**
     * This employee's Daily Time Records (one per day).
     *
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * This employee's raw attendance punch events.
     *
     * @return HasMany<AttendancePunch, $this>
     */
    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    /**
     * This employee's performance evaluations (one per review period).
     *
     * @return HasMany<PerformanceEvaluation, $this>
     */
    public function performanceEvaluations(): HasMany
    {
        return $this->hasMany(PerformanceEvaluation::class);
    }

    /**
     * This employee's training-program enrollments.
     *
     * @return HasMany<TrainingEnrollment, $this>
     */
    public function trainingEnrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    /**
     * Recognitions this employee has received.
     *
     * @return HasMany<EmployeeAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(EmployeeAward::class);
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
     * The employee's photo as a servable URL: an absolute URL is passed through
     * as-is (e.g. seeded demo portraits), otherwise the stored path is resolved
     * on the `public` disk. Null when no photo is set.
     *
     * @return Attribute<string|null, never>
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->photo) {
                    return null;
                }

                return Str::startsWith($this->photo, ['http://', 'https://'])
                    ? $this->photo
                    : Storage::disk('public')->url($this->photo);
            },
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

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $query) use ($needle, $like) {
            foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email', 'phone'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
