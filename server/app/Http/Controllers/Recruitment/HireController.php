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
     * Delegates to {@see ApplicantHirer}, which creates the Employee, provisions
     * the new hire's mobile-app login, copies the résumé into the new 201 file,
     * seeds onboarding, marks the application hired and fills the posting when its
     * openings are met. See ADR 0006 / 0007. The recruiter can opt out of emailing
     * the login credentials via `send_credentials`.
     */
    public function __invoke(Request $request, JobApplication $application): RedirectResponse
    {
        $application->load(['applicant', 'jobPosting']);

        $sendCredentials = $request->boolean('send_credentials', true);

        try {
            $employee = ApplicantHirer::hire($application, $request->user(), $sendCredentials);
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
