<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Support\ApplicantHirer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class HireController extends Controller
{
    /**
     * Hire an applicant — the recruitment → workforce bridge.
     *
     * Delegates to {@see ApplicantHirer}, which creates the Employee, copies the
     * résumé into the new 201 file, seeds onboarding, marks the application hired
     * and fills the posting when its openings are met. See ADR 0006 / 0007.
     *
     * Hiring no longer creates a login (ADR 0026): the new hire is *invited* to
     * claim the roster line with an account they register themselves. The recruiter
     * can hold that invitation back with `send_invitation`.
     */
    public function __invoke(Request $request, JobApplication $application): RedirectResponse
    {
        $application->load(['applicant', 'jobPosting']);

        $sendInvitation = $request->boolean('send_invitation', true);

        try {
            $employee = ApplicantHirer::hire($application, $request->user(), $sendInvitation);
        } catch (RuntimeException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond("{$application->applicant->full_name} hired — employee {$employee->employee_no} created.");
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
