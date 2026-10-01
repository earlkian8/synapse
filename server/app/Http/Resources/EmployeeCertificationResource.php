<?php

namespace App\Http\Resources;

use App\Models\EmployeeCertification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'url' => $this->file ? Storage::disk('public')->url($this->file) : null,
        ];
    }
}
