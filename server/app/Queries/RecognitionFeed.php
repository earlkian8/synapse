<?php

namespace App\Queries;

use App\Models\AwardNomination;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\Kudos;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Support\OrganizationClock;
use App\Support\Recognition\PointsLedger;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/**
 * What the recognition screens show (ADR 0071), shaped once for the web and the
 * mobile app alike: the wall — kudos and awards together, newest first — and
 * the people, nominations, rewards, requests and ledger lines on it. Only what
 * a colleague may see of a colleague: name, initials, photo, position and
 * department.
 */
final class RecognitionFeed
{
    /** How far back the wall reaches. */
    public const DAYS = 90;

    /** The most items the wall shows. */
    public const LIMIT = 60;

    /**
     * @return list<array<string, mixed>>
     */
    public static function wall(bool $canRemove = false): array
    {
        $since = now()->subDays(self::DAYS);
        $people = ['employee:id,first_name,middle_name,last_name,suffix,photo,position_id,department_id', 'employee.position:id,title'];

        $kudos = Kudos::query()
            ->where('created_at', '>=', $since)
            ->with([
                'sender:id,first_name,middle_name,last_name,suffix,photo,position_id',
                'recipient:id,first_name,middle_name,last_name,suffix,photo,position_id',
                'recipient.position:id,title',
            ])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Kudos $kudos): array => [
                'kind' => 'kudos',
                'id' => $kudos->id,
                'at' => $kudos->created_at?->toIso8601String(),
                'from' => self::person($kudos->sender),
                'to' => self::person($kudos->recipient),
                'message' => $kudos->message,
                'points' => $kudos->points,
                'can_remove' => $canRemove,
            ]);

        $awards = EmployeeAward::query()
            ->where('awarded_on', '>=', OrganizationClock::now()->subDays(self::DAYS)->toDateString())
            ->with([...$people, 'awardType:id,name,color,points'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EmployeeAward $award): array => [
                'kind' => 'award',
                'id' => $award->id,
                'at' => self::awardedAt($award),
                'awarded_on' => $award->awarded_on?->toDateString(),
                'from' => null,
                'to' => self::person($award->employee),
                'message' => $award->reason,
                'points' => (int) ($award->awardType?->points ?? 0),
                'award_type' => $award->awardType ? [
                    'name' => $award->awardType->name,
                    'color' => $award->awardType->color,
                ] : null,
                'can_remove' => false,
            ]);

        return $kudos->concat($awards)
            ->sortByDesc('at')
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /**
     * When an award took place, for the wall's order: the moment it was entered
     * when that was on the day it is dated, otherwise midday of its date — an
     * award entered today for last Tuesday sits with last Tuesday.
     */
    private static function awardedAt(EmployeeAward $award): ?string
    {
        if ($award->awarded_on === null) {
            return $award->created_at?->toIso8601String();
        }

        $day = $award->awarded_on->toDateString();

        if ($award->created_at !== null && OrganizationClock::localDate($award->created_at) === $day) {
            return $award->created_at->toIso8601String();
        }

        return OrganizationClock::at($day, '12:00')->toIso8601String();
    }

    /**
     * The person's own standing: balance, and the kudos with points they can
     * still give this month.
     *
     * @return array<string, mixed>
     */
    public static function me(?Employee $employee): array
    {
        $organization = app(Tenancy::class)->organization();
        $ledger = app(PointsLedger::class);

        return [
            'has_employee' => $employee !== null,
            'balance' => $employee ? $ledger->balance($employee) : 0,
            'kudos_left' => $employee ? $ledger->kudosLeftThisMonth($employee) : 0,
            'kudos_points' => (int) ($organization?->kudos_points ?? 0),
            'kudos_monthly_limit' => (int) ($organization?->kudos_monthly_limit ?? 0),
        ];
    }

    /**
     * Active colleagues other than the person — for the kudos and nomination
     * pickers. A search matches every word against the name.
     *
     * @return list<array<string, mixed>>
     */
    public static function colleagues(?Employee $except, ?string $search = null, int $limit = 500): array
    {
        $query = Employee::query()
            ->where('employment_status', 'active')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->with(['position:id,title', 'department:id,name'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        foreach (preg_split('/\s+/', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $query->search($token);
        }

        return $query->limit($limit)->get()->map(fn (Employee $employee): array => self::person($employee))->all();
    }

    /**
     * Award types colleagues can nominate for.
     *
     * @return list<array<string, mixed>>
     */
    public static function nominatableTypes(): array
    {
        return AwardType::query()->nominatable()->orderBy('name')->get()
            ->map(fn (AwardType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'color' => $type->color,
                'points' => $type->points,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function person(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'initials' => $employee->initials(),
            'photo' => $employee->photo_url,
            'position' => $employee->relationLoaded('position') ? $employee->position?->title : null,
            'department' => $employee->relationLoaded('department') ? $employee->department?->name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nomination(AwardNomination $nomination): array
    {
        return [
            'id' => $nomination->id,
            'status' => $nomination->status,
            'reason' => $nomination->reason,
            'created_at' => $nomination->created_at?->toIso8601String(),
            'reviewed_at' => $nomination->reviewed_at?->toIso8601String(),
            'review_note' => $nomination->review_note,
            'nominee' => self::person($nomination->employee),
            'nominator' => $nomination->nominator
                ? trim("{$nomination->nominator->first_name} {$nomination->nominator->last_name}")
                : null,
            'reviewer' => $nomination->reviewer
                ? trim("{$nomination->reviewer->first_name} {$nomination->reviewer->last_name}")
                : null,
            'award_type' => $nomination->awardType ? [
                'id' => $nomination->awardType->id,
                'name' => $nomination->awardType->name,
                'color' => $nomination->awardType->color,
                'points' => $nomination->awardType->points,
            ] : null,
            'award' => $nomination->award ? [
                'id' => $nomination->award->id,
                'reason' => $nomination->award->reason,
                'awarded_on' => $nomination->award->awarded_on?->toDateString(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reward(Reward $reward, ?int $balance = null): array
    {
        return [
            'id' => $reward->id,
            'hashid' => $reward->hashid,
            'name' => $reward->name,
            'description' => $reward->description,
            'cost' => $reward->cost,
            'stock' => $reward->stock,
            'is_active' => $reward->is_active,
            'is_archived' => $reward->trashed(),
            'affordable' => $balance === null ? null : $balance >= $reward->cost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function redemption(RewardRedemption $redemption): array
    {
        return [
            'id' => $redemption->id,
            'status' => $redemption->status,
            'cost' => $redemption->cost,
            'note' => $redemption->note,
            'response_note' => $redemption->response_note,
            'created_at' => $redemption->created_at?->toIso8601String(),
            'handled_at' => $redemption->handled_at?->toIso8601String(),
            'reward' => $redemption->reward ? ['name' => $redemption->reward->name] : null,
            'employee' => self::person($redemption->employee),
            'handler' => $redemption->handler
                ? trim("{$redemption->handler->first_name} {$redemption->handler->last_name}")
                : null,
        ];
    }

    /**
     * @param  Collection<int, PointTransaction>  $lines
     * @return list<array<string, mixed>>
     */
    public static function history(Collection $lines): array
    {
        return $lines->map(fn (PointTransaction $line): array => [
            'id' => $line->id,
            'amount' => $line->amount,
            'kind' => $line->kind,
            'note' => $line->note,
            'at' => $line->created_at?->toIso8601String(),
        ])->all();
    }
}
