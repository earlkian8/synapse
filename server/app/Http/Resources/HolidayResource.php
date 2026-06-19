<?php

namespace App\Http\Resources;

use App\Models\Holiday;
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
            // Year is meaningless for a recurring holiday — surface month/day too.
            'month' => $this->date?->month,
            'day' => $this->date?->day,
        ];
    }
}
