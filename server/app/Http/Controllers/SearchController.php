<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalSearchRequest;
use App\Support\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;

/**
 * The ⌘K palette's one endpoint (ADR 0069). Everything it answers comes from
 * {@see GlobalSearch}, read for the signed-in person.
 */
class SearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearch $search): JsonResponse
    {
        $query = $request->searchTerm();

        return response()->json([
            'query' => $query,
            'groups' => $search->search($request->user(), $query),
        ]);
    }
}
