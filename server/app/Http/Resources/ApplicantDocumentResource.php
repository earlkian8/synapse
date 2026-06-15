<?php

namespace App\Http\Resources;

use App\Models\ApplicantDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApplicantDocument
 */
class ApplicantDocumentResource extends JsonResource
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
            'url' => $this->fileUrl(),
            'uploaded_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
