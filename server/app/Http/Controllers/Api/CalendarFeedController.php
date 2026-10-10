<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app's calendar subscription link (ADR 0070) — the same link as
 * My events on the web, made on first ask, and replaced on reset.
 */
class CalendarFeedController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(CalendarFeed::issueFor($request->user())->links());
    }

    public function reset(Request $request): JsonResponse
    {
        return response()->json(CalendarFeed::issueFor($request->user())->reset()->links());
    }
}
