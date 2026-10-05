<?php

namespace App\Http\Resources;

use App\Models\DataExport;
use App\Support\DataExport\DataExportCatalogue;
use App\Support\DataExport\DataExports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One export in the history, with what the viewer may do with it — so the screen
 * only offers what the server will allow.
 *
 * @mixin DataExport
 */
class DataExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $exports = app(DataExports::class);

        return [
            'hashid' => $this->hashid,
            'status' => $this->displayStatus(),
            'format' => $this->format,
            'datasets' => collect($this->datasets)
                ->map(fn (string $key): array => [
                    'key' => $key,
                    'label' => DataExportCatalogue::definition($key)['label'] ?? $key,
                    'rows' => $this->summary['datasets'][$key]['rows'] ?? null,
                ])
                ->values()
                ->all(),
            'include_files' => $this->include_files,
            'rows' => $this->summary['rows'] ?? null,
            'files' => $this->summary['files'] ?? null,
            'size_bytes' => $this->size_bytes,
            'error' => $this->isStale() ? DataExport::STALE_ERROR : $this->error,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'name' => $this->requester->full_name,
                'initials' => strtoupper(mb_substr((string) $this->requester->first_name, 0, 1).mb_substr((string) $this->requester->last_name, 0, 1)),
                'avatar' => $this->requester->avatar,
            ] : null),
            'is_mine' => $this->requested_by === $user?->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_count' => $this->download_count,
            'last_downloaded_at' => $this->last_downloaded_at?->toIso8601String(),
            'can_download' => $user !== null && $exports->canDownload($user, $this->resource),
            'can_delete' => $user !== null && $exports->canDelete($user, $this->resource),
        ];
    }
}
