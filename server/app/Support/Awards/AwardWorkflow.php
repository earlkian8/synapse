<?php

namespace App\Support\Awards;

use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Recognition\PointsLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that changes a recognition, in one place: give one, revise it, or
 * take it back.
 *
 * The Awards screens and the assistant both come through here, so a
 * recognition is refused for the same reasons — in the same words
 * ({@see AwardException}) — however it was asked for:
 *
 * - **The award type must still be given out.** An archived or inactive type
 *   stays on the awards already given for it, and a revision that keeps it is
 *   fine; giving it anew, or switching an award to it, is not.
 * - **An award is not dated in the future**, by the organisation's calendar.
 *
 * The granting user is recorded on every award. Its type's points go to the
 * recipient's balance (ADR 0071): credited when it is given, the difference
 * posted when its type changes, reversed when it is taken back. The recipient
 * is told when it is given. `$channel` is appended to the audit description
 * (" via assistant").
 */
class AwardWorkflow
{
    public function __construct(private readonly PointsLedger $ledger) {}

    /**
     * @throws AwardException
     */
    public function give(Employee $employee, AwardType $type, CarbonInterface|string $awardedOn, ?string $reason, ?User $by, string $channel = ''): EmployeeAward
    {
        $this->assertGivable($type);
        $date = $this->assertDate($awardedOn);

        $award = DB::transaction(function () use ($employee, $type, $date, $reason, $by): EmployeeAward {
            $award = EmployeeAward::create([
                'employee_id' => $employee->id,
                'award_type_id' => $type->id,
                'awarded_on' => $date,
                'reason' => $reason,
                'awarded_by' => $by?->id,
            ]);

            $this->ledger->post($employee, (int) $type->points, 'award', $award, $type->name, $by);

            return $award;
        });

        $this->tell($employee, $type, $reason, $by);

        ActivityLogger::log(
            event: 'created',
            description: "Recognised {$employee->full_name} — {$type->name}{$channel}",
            subject: $award,
            logName: 'awards',
            subjectLabel: $employee->full_name,
        );

        return $award;
    }

    /**
     * Change an award's type, date or reason. Only the keys given change; the
     * recipient never does.
     *
     * @param  array{award_type_id?: int|string, awarded_on?: CarbonInterface|string, reason?: string|null}  $data
     *
     * @throws AwardException
     */
    public function revise(EmployeeAward $award, array $data, string $channel = ''): EmployeeAward
    {
        $changes = array_intersect_key($data, array_flip(['award_type_id', 'awarded_on', 'reason']));

        if (array_key_exists('award_type_id', $changes) && (int) $changes['award_type_id'] !== (int) $award->award_type_id) {
            $this->assertGivable(AwardType::query()->find($changes['award_type_id']));
        }

        if (array_key_exists('awarded_on', $changes)) {
            $changes['awarded_on'] = $this->assertDate($changes['awarded_on']);
        }

        DB::transaction(function () use ($award, $changes): void {
            $award->update($changes);

            // A new type carries its own points: post the difference.
            if ($award->wasChanged('award_type_id') && $award->employee) {
                $type = AwardType::withTrashed()->find($award->award_type_id);
                $this->ledger->settle($award->employee, $award, (int) ($type?->points ?? 0), 'award', 'Award changed to '.($type?->name ?? 'another type'), null);
            }
        });

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a recognition'.$channel,
            subject: $award,
            logName: 'awards',
            subjectLabel: $award->employee?->full_name,
        );

        return $award;
    }

    /**
     * Take a recognition back.
     */
    public function remove(EmployeeAward $award, string $channel = ''): void
    {
        $name = $award->employee?->full_name;

        DB::transaction(function () use ($award): void {
            if ($award->employee) {
                $this->ledger->settle($award->employee, $award, 0, 'award', 'Award taken back', null);
            }

            $award->delete();
        });

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed a recognition'.$channel,
            logName: 'awards',
            subjectLabel: $name,
        );
    }

    /**
     * Tell the recipient, if they can sign in. Best-effort.
     */
    private function tell(Employee $employee, AwardType $type, ?string $reason, ?User $by): void
    {
        $user = $employee->user;

        if (! $user || ! $user->is_active) {
            return;
        }

        Notifier::toUser(
            user: $user,
            title: "You've been recognised: {$type->name}".($type->points > 0 ? " (+{$type->points} points)" : ''),
            body: $reason ? Str::limit($reason, 160) : 'Congratulations!',
            url: '/awards/wall',
            level: 'success',
            category: 'awards',
            actor: $by,
        );
    }

    /**
     * @throws AwardException
     */
    private function assertGivable(?AwardType $type): void
    {
        if ($type === null || $type->trashed()) {
            throw new AwardException('That award type has been archived and is no longer given out.');
        }

        if (! $type->is_active) {
            throw new AwardException("“{$type->name}” is no longer given out.");
        }
    }

    /**
     * @throws AwardException
     */
    private function assertDate(CarbonInterface|string $date): string
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->toDateString() : $date)->toDateString();

        if ($day > OrganizationClock::today()) {
            throw new AwardException('An award can’t be dated in the future.');
        }

        return $day;
    }
}
