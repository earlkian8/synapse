<?php

namespace App\Support\Training;

use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything that changes a training program or its roster, in one place:
 * create, edit and archive a program; enroll people, grade them, move them
 * between enrolled / completed / dropped, and take them off the roster.
 *
 * The Training screens and the assistant both come through here, so a program
 * fills up, a completion is stamped and an enrollment is removed by the same
 * rules however the request arrived. `completed_at` is never taken from the
 * caller — it follows the status.
 *
 * `$channel` is appended to the audit description (" via assistant"), so the
 * trail says how a change was made as well as who made it.
 */
class TrainingWorkflow
{
    /** The status each bulk roster action leaves behind. */
    public const BULK_STATUSES = ['complete' => 'completed', 'drop' => 'dropped', 'enroll' => 'enrolled'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): TrainingProgram
    {
        $program = TrainingProgram::create($data);

        ActivityLogger::log(
            event: 'created',
            description: "Created training program \"{$program->name}\"{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $program;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TrainingProgram $program, array $data, string $channel = ''): TrainingProgram
    {
        $program->update($data);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated training program \"{$program->name}\"{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $program;
    }

    /**
     * Archive (soft delete) a program. Its roster stays, so restoring it brings
     * everyone back.
     */
    public function archive(TrainingProgram $program, string $channel = ''): void
    {
        $name = $program->name;
        $program->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived training program \"{$name}\"{$channel}",
            logName: 'training',
            subjectLabel: $name,
        );
    }

    /**
     * Enroll people into a program. Only active employees who are not already on
     * the roster are eligible, and capacity is respected — so a partial enroll
     * still succeeds for everyone who fits, and the outcome says who did not.
     *
     * @param  iterable<int>  $employeeIds
     */
    public function enroll(TrainingProgram $program, iterable $employeeIds, string $channel = ''): EnrollmentOutcome
    {
        $requested = collect($employeeIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $alreadyEnrolled = $program->enrollments()->pluck('employee_id')->all();

        // The tenant scope keeps this to the current organisation's people.
        $eligible = Employee::query()
            ->whereIn('id', $requested)
            ->where('employment_status', 'active')
            ->whereNotIn('id', $alreadyEnrolled)
            ->pluck('id');

        $ineligible = $requested->count() - $eligible->count();

        if ($eligible->isEmpty()) {
            return new EnrollmentOutcome([], $ineligible, 0);
        }

        // Trim to the seats that remain (uncapped programs take everyone).
        $program->loadCount(['enrollments as active_enrollments_count' => fn ($query) => $query->active()]);
        $remaining = $program->capacity === null
            ? $eligible->count()
            : max(0, $program->capacity - (int) $program->active_enrollments_count);

        if ($remaining <= 0) {
            return new EnrollmentOutcome([], $ineligible, $eligible->count());
        }

        $toEnroll = $eligible->take($remaining)->values();

        $program->enrollments()->createMany(
            $toEnroll->map(fn (int $employeeId): array => [
                'employee_id' => $employeeId,
                'status' => 'enrolled',
            ])->all(),
        );

        ActivityLogger::log(
            event: 'created',
            description: "Enrolled {$toEnroll->count()} ".Str::plural('employee', $toEnroll->count())." in {$program->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return new EnrollmentOutcome(
            $toEnroll->map(fn (mixed $id): int => (int) $id)->all(),
            $ineligible,
            $eligible->count() - $toEnroll->count(),
        );
    }

    /**
     * Update one enrollment's status, score or remarks. Only the keys given
     * change; the employee and program never do.
     *
     * @param  array{status?: string, score?: float|int|string|null, remarks?: string|null}  $data
     */
    public function grade(TrainingEnrollment $enrollment, array $data, string $channel = ''): TrainingEnrollment
    {
        $enrollment->update($this->withCompletion(
            array_intersect_key($data, array_flip(['status', 'score', 'remarks'])),
            $enrollment,
        ));

        $enrollment->loadMissing(['employee', 'program']);
        $who = $enrollment->employee?->full_name;

        ActivityLogger::log(
            event: 'updated',
            description: ($who !== null ? "Updated {$who}'s training enrollment" : 'Updated a training enrollment').$channel,
            subject: $enrollment->program,
            logName: 'training',
            subjectLabel: $enrollment->program?->name,
        );

        return $enrollment;
    }

    /**
     * Apply one roster action to many enrollments at once: mark them completed /
     * dropped / (re)enrolled, or remove them.
     *
     * @param  Collection<int, TrainingEnrollment>  $enrollments
     * @return string What was done, for the toast.
     */
    public function bulk(Collection $enrollments, string $action, string $channel = ''): string
    {
        $count = $enrollments->count();
        $noun = Str::plural('enrollment', $count);
        $programId = $enrollments->first()?->training_program_id;

        if ($action === 'remove') {
            TrainingEnrollment::query()->whereKey($enrollments->modelKeys())->delete();
            $this->logBulk("Removed {$count} training {$noun}{$channel}", $programId);

            return "{$count} {$noun} removed.";
        }

        $status = self::BULK_STATUSES[$action] ?? 'enrolled';

        foreach ($enrollments as $enrollment) {
            $enrollment->update($this->withCompletion(['status' => $status], $enrollment));
        }

        $this->logBulk("Marked {$count} training {$noun} as {$status}{$channel}", $programId);

        return "{$count} {$noun} marked {$status}.";
    }

    /**
     * Take one person off a program's roster.
     */
    public function remove(TrainingEnrollment $enrollment, string $channel = ''): void
    {
        $enrollment->loadMissing(['employee', 'program']);
        $program = $enrollment->program;
        $who = $enrollment->employee?->full_name;

        $enrollment->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: ($who !== null ? "Removed {$who} from the training roster" : 'Removed a training enrollment').$channel,
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );
    }

    /**
     * Keep `completed_at` in step with the status: stamp it when an enrollment
     * becomes completed (preserving an existing timestamp), clear it otherwise.
     * A change that does not touch the status leaves it alone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCompletion(array $data, TrainingEnrollment $current): array
    {
        if (! array_key_exists('status', $data)) {
            return $data;
        }

        $data['completed_at'] = $data['status'] === 'completed'
            ? ($current->completed_at ?? now())
            : null;

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
}
