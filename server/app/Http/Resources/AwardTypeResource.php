<?php

namespace App\Http\Resources;

use App\Models\AwardType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AwardType
 */
class AwardTypeResource extends JsonResource
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
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->deleted_at !== null,
            'awards_count' => (int) ($this->awards_count ?? 0),
        ];
    }
}
