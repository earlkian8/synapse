<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pass-through resource for an already-presented trash item (see TrashPresenter).
 *
 * Wrapping the manually-built paginator in a resource collection gives the Trash
 * Bin the same `{ data, links, meta }` envelope every other module's listing
 * uses, so the shared `Paginated<T>` type and pagination component apply as-is.
 */
class TrashItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $item */
        $item = $this->resource;

        return $item;
    }
}
