<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * The ordered pipeline stages an application moves through.
     *
     * @var list<string>
     */
    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    protected $fillable = [
        'organization_id',
        'job_posting_id',
        'applicant_id',
        'stage',
        'rating',
        'expected_salary',
        'cover_note',
        'rejected_reason',
        'hired_employee_id',
        'applied_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'expected_salary' => 'decimal:2',
            'applied_at' => 'datetime',
            'decided_at' => 'datetime',
            'ai_insights' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<Applicant, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * The employee created when this application was hired, if any.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    /**
     * @return HasMany<Interview, $this>
     */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether this application has been converted into an employee.
     */
    public function isHired(): bool
    {
        return $this->stage === 'hired' && $this->hired_employee_id !== null;
    }

    /**
     * Whether the application is still active in the pipeline.
     */
    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['hired', 'rejected'], true);
    }
}
