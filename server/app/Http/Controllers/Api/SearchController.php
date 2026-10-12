<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GlobalSearchRequest;
use App\Support\Search\MySearch;
use Illuminate\Http\JsonResponse;

/**
 * The mobile app's search (ADR 0081): the person's own leave, days,
 * invitations, awards and holidays ({@see MySearch}). Somebody whose account is
 * not linked to an employee has nothing of their own to find.
 */
class SearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, MySearch $search): JsonResponse
    {
        $query = $request->searchTerm();
        $employee = $request->user()->employee()->first();

        return response()->json([
            'query' => $query,
            'groups' => $employee === null ? [] : $search->search($request->user(), $employee, $query),
        ]);
    }
}
