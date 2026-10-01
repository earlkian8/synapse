<?php

namespace App\Http\Resources;

use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin EmployeeDocument
 */
class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'url' => $this->file ? Storage::disk('public')->url($this->file) : null,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
