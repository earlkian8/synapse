<?php

namespace App\Queries\Setup;

use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Leave Types: the kinds of leave the organisation grants, with
 * their entitlement and policy.
 */
class LeaveTypesScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        return [
            'types' => LeaveTypeResource::collection($this->listing()->get())->resolve($request),
            'archived' => LeaveTypeResource::collection(
                $this->listing()->onlyTrashed()->get()
            )->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.leave-types.manage')],
        ];
    }

    /**
     * The base listing query, shared by the active and archived sets.
     *
     * @return Builder<LeaveType>
     */
    private function listing(): Builder
    {
        return LeaveType::query()
            ->withCount('requests')
            ->orderByDesc('is_active')
            ->orderBy('name');
    }
}
