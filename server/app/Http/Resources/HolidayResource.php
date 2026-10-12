<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use App\Models\WorkLocation;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'date' => $this->date?->toDateString(),
            'type' => $this->type,
            'is_recurring' => (bool) $this->is_recurring,
            // How it repeats (ADR 0078), and the rule's numbers for a movable one.
            'recurrence' => $this->recurrence ?? ($this->is_recurring ? 'yearly' : 'none'),
            'rule' => $this->rule,
            'repeats' => $this->repeatsLabel(),
            // Year is meaningless for a recurring holiday — surface month/day too.
            'month' => $this->date?->month,
            'day' => $this->date?->day,
            // The next date it falls on, from the organisation's today.
            'next_date' => $this->nextOccurrence(CarbonImmutable::parse(OrganizationClock::today()))?->toDateString(),
            // The sites a local holiday is kept at; empty = everywhere.
            'locations' => $this->whenLoaded('locations', fn () => $this->locations
                ->map(fn (WorkLocation $location): array => ['id' => $location->id, 'name' => $location->name])
                ->values()
                ->all()),
        ];
    }
}
