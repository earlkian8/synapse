<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'department_id' => $this->department_id,
            'salary_grade_min' => $this->salary_grade_min,
            'salary_grade_max' => $this->salary_grade_max,
            'description' => $this->description,
            'employees_count' => $this->whenCounted('employees'),
            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
