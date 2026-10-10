<?php

namespace App\Support\Recognition;

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Awards\AwardException;
use App\Support\Awards\AwardWorkflow;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nominate → approve (ADR 0071). Anybody taking part nominates a colleague for
 * an award with why; whoever reviews nominations (`awards.manage`) approves it
 * — which gives the award through {@see AwardWorkflow}, points and all — or
 * turns it down. The nominator may withdraw it while it waits, and hears the
 * decision either way.
 *
 * Refused, in words ({@see RecognitionException}): nominating oneself, an
 * inactive colleague, a type that is not given out or not open to nominations,
 * a reason under 20 characters, and a second pending nomination of the same
 * person for the same award by the same nominator. Nobody reviews a nomination
 * they made, or one of themselves.
 */
class NominationWorkflow
{
    public const MIN_REASON = 20;

    public const MAX_REASON = 1000;

    public function __construct(private readonly AwardWorkflow $awards) {}

    /**
     * @throws RecognitionException
     */
    public function nominate(Employee $nominee, AwardType $type, string $reason, User $by, string $channel = ''): AwardNomination
    {
        $reason = trim($reason);
        $nominator = $by->employee()->first();

        if ($nominee->user_id === $by->id || ($nominator && $nominator->is($nominee))) {
            throw new RecognitionException('You can’t nominate yourself.', 'employee_id');
        }

        if ($nominee->employment_status !== 'active') {
            throw new RecognitionException('Only active colleagues can be nominated.', 'employee_id');
        }

        if ($type->trashed() || ! $type->is_active || ! $type->accepts_nominations) {
            throw new RecognitionException("“{$type->name}” isn’t open to nominations.", 'award_type_id');
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new RecognitionException('Say why in at least 20 characters — it becomes the citation.', 'reason');
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new RecognitionException('Keep the reason to 1,000 characters.', 'reason');
        }

        $waiting = AwardNomination::query()->pending()
            ->where('employee_id', $nominee->id)
            ->where('award_type_id', $type->id)
            ->where('nominated_by', $by->id)
            ->exists();

        if ($waiting) {
            throw new RecognitionException("You already nominated {$nominee->full_name} for {$type->name} — it’s waiting for review.", 'employee_id');
        }

        $nomination = AwardNomination::create([
            'award_type_id' => $type->id,
            'employee_id' => $nominee->id,
            'nominated_by' => $by->id,
            'nominator_employee_id' => $nominator?->id,
            'reason' => $reason,
        ]);

        Notifier::toPermission(
            permission: 'awards.manage',
            title: "New nomination: {$nominee->full_name} for {$type->name}",
            body: Str::limit($reason, 160),
            url: '/awards/nominations',
            level: 'info',
            category: 'awards',
            actor: $by,
            except: array_values(array_filter([$by->id, $nominee->user_id])),
        );

        ActivityLogger::log(
            event: 'created',
            description: "Nominated {$nominee->full_name} for {$type->name}{$channel}",
            subject: $nomination,
            logName: 'awards',
            subjectLabel: $nominee->full_name,
        );

        return $nomination;
    }

    /**
     * The nominator takes it back while it waits.
     *
     * @throws RecognitionException
     */
    public function withdraw(AwardNomination $nomination, User $by, string $channel = ''): void
    {
        if ($nomination->nominated_by !== $by->id) {
            throw new RecognitionException('Only who nominated can withdraw it.');
        }

        $this->assertPending($nomination);

        $nomination->update(['status' => 'withdrawn']);

        ActivityLogger::log(
            event: 'updated',
            description: 'Withdrew a nomination of '.($nomination->employee?->full_name ?? 'a colleague').$channel,
            subject: $nomination,
            logName: 'awards',
            subjectLabel: $nomination->employee?->full_name,
        );
    }

    /**
     * Give the award. The citation starts as the nominator's reason; the date is
     * the organisation's today unless given.
     *
     * @throws RecognitionException
     */
    public function approve(AwardNomination $nomination, User $reviewer, ?string $citation, ?string $awardedOn, string $channel = ''): EmployeeAward
    {
        $this->assertReviewable($nomination, $reviewer);

        $award = DB::transaction(function () use ($nomination, $reviewer, $citation, $awardedOn, $channel): EmployeeAward {
            try {
                $award = $this->awards->give(
                    $nomination->employee,
                    $nomination->awardType,
                    $awardedOn ?: OrganizationClock::today(),
                    filled($citation) ? trim($citation) : $nomination->reason,
                    $reviewer,
                    $channel,
                );
            } catch (AwardException $e) {
                throw new RecognitionException($e->getMessage());
            }

            $nomination->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'employee_award_id' => $award->id,
            ]);

            return $award;
        });

        $this->tellNominator($nomination, $reviewer, "Your nomination was approved: {$nomination->employee->full_name} — {$nomination->awardType->name}", 'They’ve been given the award. Thank you for speaking up.', 'success');

        ActivityLogger::log(
            event: 'updated',
            description: "Approved the nomination of {$nomination->employee->full_name} for {$nomination->awardType->name}{$channel}",
            subject: $nomination,
            logName: 'awards',
            subjectLabel: $nomination->employee->full_name,
        );

        return $award;
    }

    /**
     * Turn it down, with an optional note the nominator reads.
     *
     * @throws RecognitionException
     */
    public function reject(AwardNomination $nomination, User $reviewer, ?string $note, string $channel = ''): void
    {
        $this->assertReviewable($nomination, $reviewer);

        $note = filled($note) ? trim($note) : null;

        $nomination->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->tellNominator(
            $nomination,
            $reviewer,
            "Your nomination wasn’t approved: {$nomination->employee?->full_name} — {$nomination->awardType?->name}",
            $note ?? 'HR reviewed it and decided not to give the award this time.',
            'info',
        );

        ActivityLogger::log(
            event: 'updated',
            description: 'Turned down the nomination of '.($nomination->employee?->full_name ?? 'a colleague')." for {$nomination->awardType?->name}{$channel}",
            subject: $nomination,
            logName: 'awards',
            subjectLabel: $nomination->employee?->full_name,
        );
    }

    /**
     * @throws RecognitionException
     */
    private function assertReviewable(AwardNomination $nomination, User $reviewer): void
    {
        $this->assertPending($nomination);

        if ($nomination->nominated_by === $reviewer->id) {
            throw new RecognitionException('You nominated them — someone else has to review it.');
        }

        if ($nomination->employee?->user_id === $reviewer->id) {
            throw new RecognitionException('You can’t review a nomination of yourself.');
        }
    }

    /**
     * @throws RecognitionException
     */
    private function assertPending(AwardNomination $nomination): void
    {
        if ($nomination->status !== 'pending') {
            throw new RecognitionException("This nomination was already {$nomination->status}.");
        }
    }

    private function tellNominator(AwardNomination $nomination, User $reviewer, string $title, string $body, string $level): void
    {
        $nominator = $nomination->nominator;

        if ($nominator && $nominator->is_active) {
            Notifier::toUser(
                user: $nominator,
                title: $title,
                body: $body,
                url: '/awards/my-nominations',
                level: $level,
                category: 'awards',
                actor: $reviewer,
            );
        }
    }
}
