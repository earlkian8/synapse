<?php

namespace App\Http\Resources;

use App\Models\ReviewTemplate;
use App\Models\ReviewTemplateItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewTemplate
 */
class ReviewTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sections = $this->sectionList();

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'description' => $this->description,
            'rating_scale_id' => $this->rating_scale_id,
            'result_display' => $this->result_display,
            'applies_to' => $this->applies_to,
            'applies_to_values' => array_map(strval(...), $this->applies_to_values ?? []),
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->trashed(),

            'sections' => $sections,
            'bands' => $this->bandList(),

            // The weight a section carries; a framework whose sections do not sum
            // to 100 still scores (weights are relative), but HR wants to see it.
            'section_weight_total' => round(array_sum(array_column($sections, 'weight')), 2),

            // A line drawing from the catalogue is shown in the catalogue's
            // current words, so the editor never offers to save wording that has
            // moved on. Its own copy stands in for a criterion since archived.
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function (ReviewTemplateItem $item): array {
                $criterion = $item->relationLoaded('criterion') ? $item->criterion : null;

                return [
                    'id' => $item->id,
                    'kpi_criterion_id' => $item->kpi_criterion_id,
                    'rating_scale_id' => $item->rating_scale_id,
                    'section_key' => $item->section_key,
                    'name' => $criterion?->name ?? $item->name,
                    'description' => $criterion?->description ?? $item->description,
                    'weight' => (float) $item->weight,
                    'sort_order' => $item->sort_order,
                ];
            })->all()),

            'items_count' => (int) ($this->items_count ?? 0),
            'evaluations_count' => (int) ($this->evaluations_count ?? 0),
        ];
    }
}
