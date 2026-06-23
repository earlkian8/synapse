<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organisation as its company profile (ADR 0005). Exposes the editable
 * identity / contact / statutory fields plus the resolved logo URL.
 *
 * @mixin Organization
 */
class CompanyProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'tin' => $this->tin,
            'sss_employer_no' => $this->sss_employer_no,
            'philhealth_employer_no' => $this->philhealth_employer_no,
            'pagibig_employer_no' => $this->pagibig_employer_no,
            'logo' => $this->logo,
            'logo_url' => $this->logo_url,
            'initials' => $this->initials(),
            'updated_human' => $this->updated_at?->diffForHumans(),
        ];
    }
}
