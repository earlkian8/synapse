<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\BulkEnrollmentRequest;
use App\Http\Requests\Training\EnrollEmployeesRequest;
use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Training\TrainingWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manage who is enrolled in a training program: enroll people (one or many at
 * once), update a single enrollment (status / score / remarks), apply a bulk
 * action across the roster, or remove an enrollment. The rules — eligibility,
 * capacity, the completion timestamp tracking the status — live in
 * {@see TrainingWorkflow}, which the assistant uses too. Thin (route gate
 * `training.manage`).
 */
class TrainingEnrollmentController extends Controller
{
    public function __construct(private readonly TrainingWorkflow $workflow) {}

    /**
     * Enroll one or more employees in the program. Already-enrolled employees are
     * skipped and capacity is respected, so a partial enroll still succeeds for
     * everyone who fits — the flash message reports exactly what happened.
     */
    public function store(EnrollEmployeesRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        [$message, $type] = $this->workflow->enroll($trainingProgram, $request->validated('employee_ids'))->message();

        return $this->respond($message, $type);
    }

    /**
     * Update a single enrollment (status / score / remarks). The employee and
     * program never change here.
     */
    public function update(TrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        $this->workflow->grade($enrollment, $request->validated());

        return $this->respond('Enrollment updated.');
    }

    /**
     * Apply one action to many enrollments at once: mark completed / dropped /
     * (re)enrolled, or remove them from the roster.
     */
    public function bulk(BulkEnrollmentRequest $request): RedirectResponse
    {
        // The tenant scope keeps this to the current organisation's rows.
        $enrollments = TrainingEnrollment::query()
            ->whereIn('id', $request->validated('enrollment_ids'))
            ->get();

        if ($enrollments->isEmpty()) {
            return $this->respond('Those enrollments are no longer available.', 'warning');
        }

        return $this->respond($this->workflow->bulk($enrollments, $request->validated('action')));
    }

    /**
     * Remove an enrollment from the program.
     */
    public function destroy(TrainingEnrollment $enrollment): RedirectResponse
    {
        $this->workflow->remove($enrollment);

        return $this->respond('Enrollment removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
