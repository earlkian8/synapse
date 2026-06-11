<?php

namespace App\Http\Resources;

use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Applicant
 */
class ApplicantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'initials' => $this->initials(),
            'email' => $this->email,
            'phone' => $this->phone,
            'headline' => $this->headline,
            'source' => $this->source,
            'resume_url' => $this->resumeUrl(),
            'notes' => $this->notes,
            'applications_count' => $this->whenCounted('applications'),
            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
