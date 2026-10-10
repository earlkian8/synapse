<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\BulkEnrollmentRequest;
use App\Http\Requests\Training\DeclineEnrollmentRequest;
use App\Http\Requests\Training\EnrollEmployeesRequest;
use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Training\TrainingSelfService;
use App\Support\Training\TrainingWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manage who is enrolled in a training program: enroll people (one or many at
 * once), update a single enrollment (status / score / remarks), apply a bulk
 * action across the roster, remove an enrollment, or decide a request to join
 * (ADR 0074, through {@see TrainingSelfService}). The rules — eligibility,
 * capacity, the completion timestamp tracking the status — live in
 * {@see TrainingWorkflow}, which the assistant uses too. Thin (route gate
 * `training.manage`).
 */
class TrainingEnrollmentController extends Controller
{
    use TrainingResponses;

    public function __construct(
        private readonly TrainingWorkflow $workflow,
        private readonly TrainingSelfService $selfService,
    ) {}

    /**
     * Enroll one or more employees in the program. Already-enrolled employees are
     * skipped and capacity is respected, so a partial enroll still succeeds for
     * everyone who fits — the flash message reports exactly what happened.
     */
    public function store(EnrollEmployeesRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        [$message, $type] = $this->workflow->enroll($trainingProgram, $request->validated('employee_ids'), by: $request->user())->message();

        return $this->toast($message, $type);
    }

    /**
     * Update a single enrollment (status / score / remarks). The employee and
     * program never change here.
     */
    public function update(TrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->workflow->grade($enrollment, $request->validated()),
            'Enrollment updated.',
        );
    }

    /**
     * Approve a request to join (ADR 0074): the person takes a seat, if one is left.
     */
    public function approve(Request $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->selfService->approve($enrollment, $request->user()),
            fn (TrainingEnrollment $e): string => "{$e->employee?->full_name} is in.",
        );
    }

    /**
     * Decline a request to join, with an optional note the person is shown.
     */
    public function decline(DeclineEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->selfService->decline($enrollment, $request->user(), $request->validated('note')),
            'Request declined.',
        );
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
            return $this->toast('Those enrollments are no longer available.', 'warning');
        }

        return $this->toast($this->workflow->bulk($enrollments, $request->validated('action')));
    }

    /**
     * Remove an enrollment from the program.
     */
    public function destroy(TrainingEnrollment $enrollment): RedirectResponse
    {
        $this->workflow->remove($enrollment);

        return $this->toast('Enrollment removed.');
    }
}
