<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FinishProductTourRequest;
use App\Support\ProductTour;
use Illuminate\Http\Response;

/**
 * Records that somebody's first tour of the app is over (ADR 0060).
 *
 * Called in the background as the tour closes, so it answers with no content
 * rather than a redirect: the page the person is on must not reload under them.
 * Not activity-logged — like a profile edit, it is the person's own account
 * state, not a change to anything the company keeps.
 */
class ProductTourController extends Controller
{
    public function finish(FinishProductTourRequest $request): Response
    {
        ProductTour::finish($request->user(), $request->validated('outcome'));

        return response()->noContent();
    }
}
