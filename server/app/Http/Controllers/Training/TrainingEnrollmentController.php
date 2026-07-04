<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\BulkEnrollmentRequest;
use App\Http\Requests\Training\EnrollEmployeesRequest;
use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Manage who is enrolled in a training program: enroll people (one or many at
 * once), update a single enrollment (status / score / remarks), apply a bulk
 * action across the roster, or remove an enrollment. The completion timestamp
 * tracks the status — stamped when an enrollment is marked completed, cleared
 * otherwise. Thin (route gate `training.manage`).
 */
class TrainingEnrollmentController extends Controller
{
    /**
     * Enroll one or more employees in the program. Already-enrolled employees are
     * skipped and capacity is respected, so a partial enroll still succeeds for
     * everyone who fits — the flash message reports exactly what happened.
     */
    public function store(EnrollEmployeesRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        $requested = collect($request->validated('employee_ids'))->unique()->values();

        // Only active employees who are not already on the roster are eligible.
        $alreadyEnrolled = $trainingProgram->enrollments()->pluck('employee_id')->all();

        $eligible = Employee::query()
            ->whereIn('id', $requested)
            ->where('employment_status', 'active')
            ->whereNotIn('id', $alreadyEnrolled)
            ->pluck('id');

        if ($eligible->isEmpty()) {
            return $this->respond('No new employees to enroll — they are already enrolled or inactive.', 'warning');
        }

        // Trim to the seats that remain (uncapped programs take everyone).
        $trainingProgram->loadCount(['enrollments as active_enrollments_count' => fn ($query) => $query->active()]);
        $remaining = $trainingProgram->capacity === null
            ? $eligible->count()
            : max(0, $trainingProgram->capacity - (int) $trainingProgram->active_enrollments_count);

        if ($remaining <= 0) {
            return $this->respond('This program is full.', 'warning');
        }

        $toEnroll = $eligible->take($remaining);
        $truncated = $eligible->count() - $toEnroll->count();

        $trainingProgram->enrollments()->createMany(
            $toEnroll->map(fn (int $employeeId): array => [
                'employee_id' => $employeeId,
                'status' => 'enrolled',
            ])->all(),
        );

        ActivityLogger::log(
            event: 'created',
            description: "Enrolled {$toEnroll->count()} ".Str::plural('employee', $toEnroll->count())." in {$trainingProgram->name}",
            subject: $trainingProgram,
            logName: 'training',
            subjectLabel: $trainingProgram->name,
        );

        $message = $toEnroll->count() === 1
            ? 'Employee enrolled.'
            : "{$toEnroll->count()} employees enrolled.";

        if ($truncated > 0) {
            return $this->respond("{$message} {$truncated} left out — the program is now full.", 'warning');
        }

        return $this->respond($message);
    }

    /**
     * Update a single enrollment (status / score / remarks). The employee and
     * program never change here.
     */
    public function update(TrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        $enrollment->update($this->withCompletion($request->validated(), $enrollment));

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a training enrollment',
            subject: $enrollment->program,
            logName: 'training',
            subjectLabel: $enrollment->program?->name,
        );

        return $this->respond('Enrollment updated.');
    }

    /**
     * Apply one action to many enrollments at once: mark completed / dropped /
     * (re)enrolled, or remove them from the roster.
     */
    public function bulk(BulkEnrollmentRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

        // The tenant scope keeps this to the current organisation's rows.
        $enrollments = TrainingEnrollment::query()
            ->whereIn('id', $request->validated('enrollment_ids'))
            ->get();

        if ($enrollments->isEmpty()) {
            return $this->respond('Those enrollments are no longer available.', 'warning');
        }

        $count = $enrollments->count();
        $noun = Str::plural('enrollment', $count);

        if ($action === 'remove') {
            TrainingEnrollment::query()->whereKey($enrollments->modelKeys())->delete();
            $this->logBulk("Removed {$count} training {$noun}", $enrollments->first()?->training_program_id);

            return $this->respond("{$count} {$noun} removed.");
        }

        $status = match ($action) {
            'complete' => 'completed',
            'drop' => 'dropped',
            default => 'enrolled',
        };

        foreach ($enrollments as $enrollment) {
            $enrollment->update($this->withCompletion(['status' => $status], $enrollment));
        }

        $this->logBulk("Marked {$count} training {$noun} as {$status}", $enrollments->first()?->training_program_id);

        return $this->respond("{$count} {$noun} marked {$status}.");
    }

    /**
     * Remove an enrollment from the program.
     */
    public function destroy(TrainingEnrollment $enrollment): RedirectResponse
    {
        $program = $enrollment->program;
        $enrollment->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed a training enrollment',
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );

        return $this->respond('Enrollment removed.');
    }

    /**
     * Keep `completed_at` in step with the status: stamp it when an enrollment
     * becomes completed (preserving an existing timestamp), clear it otherwise.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCompletion(array $data, ?TrainingEnrollment $current = null): array
    {
        if (($data['status'] ?? null) === 'completed') {
            $data['completed_at'] = $current?->completed_at ?? now();
        } else {
            $data['completed_at'] = null;
        }

        return $data;
    }

    private function logBulk(string $description, ?int $programId): void
    {
        ActivityLogger::log(
            event: 'updated',
            description: $description,
            subject: $programId !== null ? TrainingProgram::find($programId) : null,
            logName: 'training',
        );
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
