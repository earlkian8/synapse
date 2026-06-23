<?php

namespace App\Http\Resources;

use App\Models\OffboardingCase;
use App\Support\OffboardingProvisioner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OffboardingCase
 */
class OffboardingCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'type' => $this->type,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'notice_date' => $this->notice_date?->toDateString(),
            'last_working_day' => $this->last_working_day?->toDateString(),
            'reason' => $this->reason,
            'completed_at' => $this->completed_at?->toIso8601String(),

            'clearance' => $this->clearance(),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'employment_type' => $this->employee->employment_type,
                'employment_status' => $this->employee->employment_status,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? ['id' => $this->employee->department->id, 'name' => $this->employee->department->name]
                    : null,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? ['id' => $this->employee->position->id, 'title' => $this->employee->position->title]
                    : null,
                'date_hired' => $this->employee->date_hired?->toDateString(),
            ] : null),

            'items' => $this->whenLoaded(
                'clearanceItems',
                fn () => ClearanceItemResource::collection($this->clearanceItems)->resolve($request),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'updated_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Clearance summary — derived from the loaded items when present, else from the
     * `*_count` aggregates added by the index query. The `status` is the derived
     * ERD §9 clearance state (pending / in_progress / cleared).
     *
     * @return array{total: int, cleared: int, flagged: int, pending: int, percent: int, status: string}
     */
    private function clearance(): array
    {
        if ($this->relationLoaded('clearanceItems')) {
            $items = $this->clearanceItems;
            $total = $items->count();
            $cleared = $items->where('status', 'cleared')->count();
            $flagged = $items->where('status', 'flagged')->count();
        } else {
            $total = (int) ($this->items_count ?? 0);
            $cleared = (int) ($this->cleared_items_count ?? 0);
            $flagged = (int) ($this->flagged_items_count ?? 0);
        }

        return [
            'total' => $total,
            'cleared' => $cleared,
            'flagged' => $flagged,
            'pending' => max(0, $total - $cleared - $flagged),
            'percent' => $total > 0 ? (int) round(($cleared / $total) * 100) : 0,
            'status' => OffboardingProvisioner::clearanceStatus($total, $cleared),
        ];
    }
}
