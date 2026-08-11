<?php

namespace App\Http\Resources;

use App\Models\RatingScale;
use App\Support\Performance\RatingScales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RatingScale
 */
class RatingScaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->definition();

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $definition['type'],
            'min' => $definition['min'],
            'max' => $definition['max'],
            'step' => $definition['step'],
            'levels' => $definition['levels'],
            'is_default' => (bool) $this->is_default,
            'is_archived' => $this->trashed(),
            // "1–5", "0–100%", "5 levels" — the instrument in three words.
            'descriptor' => RatingScales::descriptor($definition),
            'usage_count' => (int) ($this->criteria_count ?? 0) + (int) ($this->items_count ?? 0),
        ];
    }
}
