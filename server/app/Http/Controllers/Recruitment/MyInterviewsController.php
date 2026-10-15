<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Queries\MyInterviews;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recruitment → My interviews (ADR 0091): the interviews this person is
 * assigned, for somebody who interviews without running the hiring board.
 * Their verdict is recorded through {@see InterviewController::update()}.
 */
class MyInterviewsController extends Controller
{
    public function index(Request $request, MyInterviews $interviews): Response
    {
        return Inertia::render('recruitment/interviews', [
            'interviews' => $interviews->for($request->user()),
        ]);
    }
}
