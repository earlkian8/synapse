<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\ProductTourRun;
use App\Queries\ProductTourFunnel;
use App\Support\ProductTour;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System → Product tour (ADR 0080): how this workspace's people move through
 * the tour on the web and in the app — how many start it, finish it, skip it or
 * leave it, and at which stop. Gated by `product-tour.view`.
 */
class ProductTourAnalyticsController extends Controller
{
    /** The periods it reads, in months. */
    private const PERIODS = [1, 3, 6, 12];

    public function __invoke(Request $request, ProductTourFunnel $funnel): Response
    {
        $platform = in_array($request->string('platform')->toString(), ProductTourRun::PLATFORMS, true)
            ? $request->string('platform')->toString()
            : 'web';
        $current = $platform === 'mobile' ? ProductTour::MOBILE_VERSION : ProductTour::VERSION;
        $version = $request->integer('version') >= 1 && $request->integer('version') <= $current ? $request->integer('version') : $current;
        $months = in_array($request->integer('months'), self::PERIODS, true) ? $request->integer('months') : 3;

        return Inertia::render('system/product-tour', [
            ...$funnel->toArray($platform, $version, $months),
            'filters' => ['platform' => $platform, 'version' => $version, 'months' => $months],
            'versions' => range($current, 1),
            'periods' => self::PERIODS,
        ]);
    }
}
