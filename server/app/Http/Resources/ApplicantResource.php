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
            'current_location' => $this->current_location,
            'headline' => $this->headline,
            'linkedin_url' => $this->linkedin_url,
            'portfolio_url' => $this->portfolio_url,
            'years_experience' => $this->years_experience,
            'source' => $this->source,
            'resume_url' => $this->resumeUrl(),
            'notes' => $this->notes,
            'documents' => ApplicantDocumentResource::collection($this->whenLoaded('documents')),
            'applications_count' => $this->whenCounted('applications'),
            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
