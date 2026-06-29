<?php

namespace App\Http\Resources;

use App\Models\JobPosting;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobPosting
 */
class JobPostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'title' => $this->title,
            'description' => $this->description,
            'requirements' => $this->requirements,
            'min_years_experience' => $this->min_years_experience,
            'skills' => $this->skills ?? [],
            'employment_type' => $this->employment_type,
            'openings' => $this->openings,
            'status' => $this->status,
            'closing_date' => $this->closing_date?->toDateString(),
            'closes_human' => $this->closing_date?->diffForHumans(['parts' => 1]),
            'days_to_close' => $this->daysToClose(),
            'is_expired' => $this->isExpired(),
            'is_open' => $this->status === 'open',
            'apply_url' => $this->publicApplyUrl(),

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'position' => $this->whenLoaded('position', fn () => $this->position ? [
                'id' => $this->position->id,
                'title' => $this->position->title,
            ] : null),
            'posted_by' => $this->whenLoaded('postedBy', fn () => $this->postedBy?->full_name),

            'department_id' => $this->department_id,
            'position_id' => $this->position_id,

            'applications_count' => $this->whenCounted('applications'),
            'open_count' => $this->open_count,
            'hired_count' => $this->hired_count,

            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The shareable public application URL for this posting.
     *
     * Built from the active tenant's slug — every posting in a recruiter's view
     * belongs to the bound organisation (see {@see Tenancy}), which is resolved
     * once per request, so it costs no per-row query.
     */
    private function publicApplyUrl(): ?string
    {
        $slug = app(Tenancy::class)->organization()?->slug;

        return $slug
            ? route('careers.show', ['organization' => $slug, 'jobPosting' => $this->hashid])
            : null;
    }
}
