<?php

namespace App\Http\Resources\Admin;

use App\Models\ProblemReport;
use App\Models\ProblemReportAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A problem report as the admin portal reads it (ADR 0096). The list row is
 * what it is about and who sent it; `detail()` adds what they wrote, what their
 * device sent with it, and how the admins have followed it up. Screenshots are
 * described here and fetched with the admin's token, never linked.
 *
 * @mixin ProblemReport
 */
class ProblemReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'impact' => $this->impact,
            'impact_label' => $this->impactLabel(),
            'status' => $this->status,
            'status_label' => $this->statusLabel('admin'),
            'platform' => $this->platform,
            'platform_label' => $this->platformLabel(),
            'reporter' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->full_name,
                'email' => $this->user->email,
            ] : null,
            'organization' => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ] : null,
            'attachments_count' => (int) ($this->attachments_count
                ?? ($this->relationLoaded('attachments') ? $this->attachments->count() : $this->attachments()->count())),
            'has_reply' => $this->reply !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Request $request): array
    {
        return [
            ...$this->toArray($request),
            'description' => $this->description,
            'page' => $this->page,
            'app_version' => $this->app_version,
            'device' => $this->device,
            'context' => $this->context ?? (object) [],
            'reply' => $this->reply,
            'replied_at' => $this->replied_at?->toIso8601String(),
            'internal_note' => $this->internal_note,
            'handled_by' => $this->handler?->name,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attachments' => $this->attachments->map(fn (ProblemReportAttachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'width' => $attachment->width,
                'height' => $attachment->height,
            ])->values()->all(),
        ];
    }
}
