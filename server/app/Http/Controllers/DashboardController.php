<?php

namespace App\Http\Controllers;

use App\Queries\DashboardOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home dashboard: a permission-aware overview of the active organisation,
 * composed by {@see DashboardOverview} from the per-module statistics.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardOverview $overview) {}

    public function index(Request $request): Response
    {
        return Inertia::render('dashboard', $this->overview->for($request->user()));
    }
}
