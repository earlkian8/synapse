<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use Database\Factories\ExitInterviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The exit-interview survey sent to a leaver (ADR 0076), one per exit. The
 * questions are kept as they were asked ({@see ExitInterviewQuestionnaire}), so
 * a later change to the questionnaire never re-labels answers already given.
 * Sent, answered, reminded about and waived through {@see ExitInterviews}.
 */
class ExitInterview extends Model
{
    /** @use HasFactory<ExitInterviewFactory> */
    use BelongsToOrganization, HasFactory;

    /** @var list<string> */
    public const STATUSES = ['pending', 'submitted', 'waived'];

    protected $fillable = [
        'organization_id',
        'offboarding_case_id',
        'employee_id',
        'status',
        'questions',
        'answers',
        'sent_at',
        'reminded_at',
        'submitted_at',
        'waived_by',
        'waived_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OffboardingCase, $this>
     */
    public function offboardingCase(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class);
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
    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
