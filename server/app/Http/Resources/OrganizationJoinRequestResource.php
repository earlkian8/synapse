<?php

namespace App\Http\Resources;

use App\Models\OrganizationJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somebody waiting at the door, as HR sees them on the App Access screen.
 *
 * Everything here comes off the *identity* they registered — the name and address
 * they chose — because that is all the organisation knows about them yet. Matching
 * it to a roster line is exactly the judgement HR is being asked to make.
 *
 * @mixin OrganizationJoinRequest
 */
class OrganizationJoinRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'requested_at' => $this->created_at?->toIso8601String(),
            'requested_human' => $this->created_at?->diffForHumans(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
                'email' => $this->user->email,
                'avatar' => $this->user->avatar,
            ] : null),
        ];
    }
}
