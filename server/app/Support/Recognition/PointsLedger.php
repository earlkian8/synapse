<?php

namespace App\Support\Recognition;

use App\Models\Employee;
use App\Models\Kudos;
use App\Models\PointTransaction;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The points ledger (ADR 0071). Every credit and debit is a line; a balance is
 * their sum. Nothing is edited in place: taking something back posts what
 * undoes it, so the history always explains the balance. A balance can dip
 * below zero after a reversal; redeeming waits until it covers the cost.
 */
class PointsLedger
{
    /** The most an adjustment can move a balance, either way. */
    public const MAX_ADJUSTMENT = 100_000;

    public function balance(Employee $employee): int
    {
        return (int) PointTransaction::query()->where('employee_id', $employee->id)->sum('amount');
    }

    /**
     * Post a line. A zero amount posts nothing.
     */
    public function post(Employee $employee, int $amount, string $kind, ?Model $subject, ?string $note, ?User $by): ?PointTransaction
    {
        if ($amount === 0) {
            return null;
        }

        return PointTransaction::create([
            'employee_id' => $employee->id,
            'amount' => $amount,
            'kind' => $kind,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'created_by' => $by?->id,
        ]);
    }

    /**
     * What a record has given so far, net — an award's points after any change
     * of type, a kudos's points.
     */
    public function netFor(Model $subject): int
    {
        return (int) PointTransaction::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->sum('amount');
    }

    /**
     * Bring a record's net to `$target`, posting the difference — how a changed
     * award type, or a removed award or kudos (target 0), is settled.
     */
    public function settle(Employee $employee, Model $subject, int $target, string $kind, string $note, ?User $by): ?PointTransaction
    {
        return $this->post($employee, $target - $this->netFor($subject), $kind, $subject, $note, $by);
    }

    /**
     * HR's correction, with the reason the person is told. Never to one's own
     * balance.
     *
     * @throws RecognitionException
     */
    public function adjust(Employee $employee, int $amount, string $note, User $by, string $channel = ''): PointTransaction
    {
        $note = trim($note);

        if ($note === '') {
            throw new RecognitionException('Say why — the person sees it with the points.', 'note');
        }

        if ($amount === 0 || abs($amount) > self::MAX_ADJUSTMENT) {
            throw new RecognitionException('An adjustment is between 1 and 100,000 points, up or down.', 'amount');
        }

        if ($employee->user_id !== null && $employee->user_id === $by->id) {
            throw new RecognitionException('You can’t adjust your own points — ask another HR manager.', 'employee_id');
        }

        $line = $this->post($employee, $amount, 'adjustment', null, $note, $by);

        $points = abs($amount).' '.str('point')->plural(abs($amount));

        if ($employee->user && $employee->user->is_active) {
            Notifier::toUser(
                user: $employee->user,
                title: $amount > 0 ? "You received {$points}" : "{$points} were taken off",
                body: $note,
                url: '/awards/points',
                level: 'info',
                category: 'awards',
                actor: $by,
            );
        }

        ActivityLogger::log(
            event: 'updated',
            description: ($amount > 0 ? "Added {$points} to" : "Took {$points} from")." {$employee->full_name}'s balance — {$note}{$channel}",
            subject: $line,
            logName: 'awards',
            subjectLabel: $employee->full_name,
        );

        return $line;
    }

    /**
     * The person's lines, latest first.
     *
     * @return Collection<int, PointTransaction>
     */
    public function history(Employee $employee, int $limit = 50): Collection
    {
        return PointTransaction::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * How many more kudos this person can give with points this calendar month,
     * on the organisation's clock.
     */
    public function kudosLeftThisMonth(Employee $employee): int
    {
        $limit = (int) (app(Tenancy::class)->organization()?->kudos_monthly_limit ?? 5);

        $since = OrganizationClock::now()->startOfMonth()->utc();

        $sent = Kudos::withTrashed()
            ->where('from_employee_id', $employee->id)
            ->where('points', '>', 0)
            ->where('created_at', '>=', $since)
            ->count();

        return max(0, $limit - $sent);
    }
}
