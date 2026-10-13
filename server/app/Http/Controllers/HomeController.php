<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/` has no page of its own (ADR 0084): the public front door is the separate
 * landing site (`landing/`). A guest is sent to sign in, and somebody already
 * signed in goes where signing in would have taken them (`fortify.home`).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()
            ? redirect(config('fortify.home'))
            : redirect()->route('login');
    }
}
