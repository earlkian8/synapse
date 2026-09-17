<?php

namespace App\Http\Resources;

use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkSchedule
 */
class WorkScheduleResource extends JsonResource
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
            'type' => $this->type,
            'cycle_length_days' => (int) $this->cycle_length_days,
            'cycle_anchor_date' => $this->cycle_anchor_date?->toDateString(),
            'weekly_required_minutes' => $this->weekly_required_minutes,
            'grace_minutes' => (int) $this->grace_minutes,

            // The pre-pattern summary: the first working day's hours and the
            // weekdays that are not rest days. Derived from the pattern, kept for
            // the screens that still read it (ADR 0037).
            'start_time' => WorkSchedule::clockFace($this->start_time),
            'end_time' => WorkSchedule::clockFace($this->end_time),
            'work_days' => $this->work_days ?? [],
            'required_hours' => (float) $this->required_hours,

            'days' => $this->patternDays()
                ->values()
                ->map(fn (WorkScheduleDay $day): array => [
                    'day_index' => $day->day_index,
                    'is_rest_day' => (bool) $day->is_rest_day,
                    'segments' => array_values($day->segments ?? []),
                    'required_minutes' => (int) $day->required_minutes,
                    'core_start' => $day->core_start,
                    'core_end' => $day->core_end,
                    'earliest_start' => $day->earliest_start,
                    'latest_end' => $day->latest_end,
                    'unpaid_break_minutes' => (int) $day->unpaid_break_minutes,
                ])
                ->all(),

            'employees_count' => (int) ($this->employees_count ?? 0),
        ];
    }
}
