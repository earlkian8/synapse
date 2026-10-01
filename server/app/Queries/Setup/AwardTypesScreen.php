<?php

namespace App\Queries\Setup;

use App\Http\Resources\AwardTypeResource;
use App\Models\AwardType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Company Setup → Award Types: the catalogue of recognitions the Awards &
 * Recognition module gives out.
 */
class AwardTypesScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        return [
            'types' => AwardTypeResource::collection($this->listing()->get())->resolve($request),
            'archived' => AwardTypeResource::collection($this->listing()->onlyTrashed()->get())->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.award-types.manage')],
        ];
    }

    /**
     * @return Builder<AwardType>
     */
    private function listing(): Builder
    {
        return AwardType::query()->withCount('awards')->catalogueOrder();
    }
}
