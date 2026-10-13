<?php

namespace App\Http\Resources;

use App\Models\EmployeeCertification;
use App\Support\Files\Uploads;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeCertification
 */
class EmployeeCertificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'issued_date' => $this->issued_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => $this->expiry_date !== null && $this->expiry_date->isPast(),
            'url' => $this->file ? Uploads::url($this->file) : null,
            // Earned by completing training (ADR 0075): withdrawn there, not here.
            'from_training' => $this->training_enrollment_id !== null,
            'certificate_no' => $this->certificate_no,
        ];
    }
}
