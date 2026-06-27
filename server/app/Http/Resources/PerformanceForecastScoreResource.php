<?php

namespace App\Http\Resources;

use App\Models\PerformanceForecast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PerformanceForecast
 */
class PerformanceForecastScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'predicted_rating' => (float) $this->predicted_rating,
            'confidence' => (float) $this->confidence,
            'band' => $this->band,
            'history' => $this->history ?? [],
            'features' => $this->features ?? [],

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? $this->employee->position->title
                    : null,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? $this->employee->department->name
                    : null,
            ] : null),
        ];
    }
}
