<?php

namespace App\Http\Resources;

use App\Models\WorkSchedule;
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
            'start_time' => $this->time($this->start_time),
            'end_time' => $this->time($this->end_time),
            'work_days' => $this->work_days ?? [],
            'grace_minutes' => (int) $this->grace_minutes,
            'required_hours' => (float) $this->required_hours,
            'employees_count' => (int) ($this->employees_count ?? 0),
        ];
    }

    /**
     * Trim a stored "HH:MM:SS" time down to "HH:MM" for the form / display.
     */
    private function time(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 5);
    }
}
