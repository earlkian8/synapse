<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Queries\MyTraining;
use App\Support\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * A training session as a calendar file, opened from the app's short-lived link
 * (ADR 0094). There is no session or token here: the signature in the address
 * is the key — minted for this session and this person, minutes ago — so it is
 * checked by the route's `signed` middleware, and the session's own company is
 * bound to read it. The person must still hold a seat on the program.
 */
class TrainingSessionCalendarController extends Controller
{
    public function __invoke(int $session, int $user, Tenancy $tenancy, MyTraining $training): Response
    {
        $sitting = TrainingSession::query()->with('organization')->find($session);
        $holder = User::query()->find($user);

        abort_if($sitting === null || $sitting->organization === null || $holder === null, 404);

        return $tenancy->runFor($sitting->organization, function () use ($sitting, $holder, $training): Response {
            $employee = Employee::query()->where('user_id', $holder->id)->first();
            abort_unless($training->holdsSeat($sitting, $employee), 404);

            [$body, $filename] = MyTraining::calendarFile($sitting);

            return response($body, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                // Inline, so the phone's browser offers to add it rather than only saving a file.
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
            ]);
        });
    }
}
