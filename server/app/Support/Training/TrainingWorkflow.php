<?php

namespace App\Support\Training;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 *
 * Since ADR 0074 / 0075: a request to join is not on the roster, so it is never
 * graded or bulk-changed here — HR's enroll approves it instead. Completing
 * someone on a program that issues certificates issues theirs
 * ({@see TrainingCertificates}); undoing it withdraws it. A program's dates
 * cannot move off its sessions. Everyone enrolled by HR is told.
 */
class TrainingWorkflow
{
    public function __construct(private readonly TrainingCertificates $certificates) {}

    /** The status each bulk roster action leaves behind. */
    public const BULK_STATUSES = ['complete' => 'completed', 'drop' => 'dropped', 'enroll' => 'enrolled'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): TrainingProgram
    {
        $program = TrainingProgram::create($this->normalized($data));

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
     * Change a program. Its dates cannot move off a session it already has.
     * Turning certificates on issues them to everyone who had completed it.
     *
     * @param  array<string, mixed>  $data
     * @return int How many certificates the change issued.
     *
     * @throws TrainingException
     */
    public function update(TrainingProgram $program, array $data, string $channel = ''): int
    {
        $this->assertSessionsFit($program, $data);

        $program->update($this->normalized($data));

        ActivityLogger::log(
            event: 'updated',
            description: "Updated training program \"{$program->name}\"{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $this->certificates->backfill($program, $channel);
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
     * Someone who asked to join (or was declined) is enrolled on their request's
     * row. Everyone enrolled is told.
     *
     * @param  iterable<int>  $employeeIds
     */
    public function enroll(TrainingProgram $program, iterable $employeeIds, string $channel = '', ?User $by = null): EnrollmentOutcome
    {
        $requested = collect($employeeIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $outcome = DB::transaction(function () use ($program, $requested, $by): array {
            // Counted under a lock, so two enrollments at once cannot overfill it.
            TrainingProgram::query()->lockForUpdate()->find($program->id);

            $rows = $program->enrollments()->get(['id', 'employee_id', 'status'])->keyBy('employee_id');
            $onRoster = $rows->filter(fn (TrainingEnrollment $e): bool => $e->isOnRoster())->keys()->all();

            // The tenant scope keeps this to the current organisation's people.
            $eligible = Employee::query()
                ->whereIn('id', $requested)
                ->where('employment_status', 'active')
                ->whereNotIn('id', $onRoster)
                ->pluck('id');

            $ineligible = $requested->count() - $eligible->count();

            if ($eligible->isEmpty()) {
                return [[], $ineligible, 0];
            }

            // Trim to the seats that remain (uncapped programs take everyone).
            $remaining = TrainingSelfService::seatsLeft($program) ?? $eligible->count();

            if ($remaining <= 0) {
                return [[], $ineligible, $eligible->count()];
            }

            $toEnroll = $eligible->take($remaining)->map(fn (mixed $id): int => (int) $id)->values();
            [$fromRequests, $fresh] = $toEnroll->partition(fn (int $id): bool => $rows->has($id));

            if ($fromRequests->isNotEmpty()) {
                TrainingEnrollment::query()
                    ->where('training_program_id', $program->id)
                    ->whereIn('employee_id', $fromRequests->all())
                    ->update(['status' => 'enrolled', 'decided_by' => $by?->id, 'decided_at' => now(), 'decision_note' => null]);
            }

            $program->enrollments()->createMany(
                $fresh->map(fn (int $employeeId): array => [
                    'employee_id' => $employeeId,
                    'status' => 'enrolled',
                ])->all(),
            );

            return [$toEnroll->all(), $ineligible, $eligible->count() - $toEnroll->count()];
        });

        [$enrolledIds, $ineligible, $leftOut] = $outcome;

        if ($enrolledIds !== []) {
            ActivityLogger::log(
                event: 'created',
                description: 'Enrolled '.count($enrolledIds).' '.Str::plural('employee', count($enrolledIds))." in {$program->name}{$channel}",
                subject: $program,
                logName: 'training',
                subjectLabel: $program->name,
            );

            $this->tellEnrolled($program, $enrolledIds, $by);
        }

        return new EnrollmentOutcome($enrolledIds, $ineligible, $leftOut);
    }

    /**
     * Update one enrollment's status, score or remarks. Only the keys given
     * change; the employee and program never do.
     *
     * @param  array{status?: string, score?: float|int|string|null, remarks?: string|null}  $data
     *
     * @throws TrainingException
     */
    public function grade(TrainingEnrollment $enrollment, array $data, string $channel = ''): TrainingEnrollment
    {
        if (! $enrollment->isOnRoster()) {
            throw new TrainingException($enrollment->status === 'requested'
                ? 'This is a request to join — approve or decline it first.'
                : 'This request was declined, so there is nothing to grade.');
        }

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

        $this->certificates->sync($enrollment, $channel);

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
        // Requests to join are decided, not bulk-changed.
        $enrollments = $enrollments->filter(fn (TrainingEnrollment $e): bool => $e->isOnRoster())->values();
        $count = $enrollments->count();

        if ($count === 0) {
            return 'Nothing to change — requests to join are approved or declined one at a time.';
        }

        $noun = Str::plural('enrollment', $count);
        $programId = $enrollments->first()?->training_program_id;

        if ($action === 'remove') {
            EmployeeCertification::query()->whereIn('training_enrollment_id', $enrollments->modelKeys())->delete();
            TrainingEnrollment::query()->whereKey($enrollments->modelKeys())->delete();
            $this->logBulk("Removed {$count} training {$noun}{$channel}", $programId);

            return "{$count} {$noun} removed.";
        }

        $status = self::BULK_STATUSES[$action] ?? 'enrolled';

        foreach ($enrollments as $enrollment) {
            $enrollment->update($this->withCompletion(['status' => $status], $enrollment));
            $this->certificates->sync($enrollment, $channel);
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

        $enrollment->certificate()->delete();
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

    /**
     * A program that issues no certificate has no validity to keep.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data): array
    {
        if (array_key_exists('issues_certificate', $data) && ! $data['issues_certificate']) {
            $data['certificate_validity_months'] = null;
        }

        return $data;
    }

    /**
     * Refuse new dates that would leave a session outside them.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TrainingException
     */
    private function assertSessionsFit(TrainingProgram $program, array $data): void
    {
        if (! array_key_exists('start_date', $data) && ! array_key_exists('end_date', $data)) {
            return;
        }

        $start = array_key_exists('start_date', $data) ? $data['start_date'] : $program->start_date?->toDateString();
        $end = array_key_exists('end_date', $data) ? $data['end_date'] : $program->end_date?->toDateString();

        if (! filled($start) && ! filled($end)) {
            return;
        }

        $outside = $program->sessions()
            ->where(function ($query) use ($start, $end): void {
                if (filled($start)) {
                    $query->orWhereDate('session_date', '<', (string) $start);
                }

                if (filled($end)) {
                    $query->orWhereDate('session_date', '>', (string) $end);
                }
            })
            ->count();

        if ($outside > 0) {
            throw new TrainingException(
                ($outside === 1 ? 'A session falls' : "{$outside} sessions fall").' outside those dates — move or cancel '.($outside === 1 ? 'it' : 'them').' first.',
                'start_date',
            );
        }
    }

    /**
     * Tell the people HR enrolled, where they have an account.
     *
     * @param  list<int>  $employeeIds
     */
    private function tellEnrolled(TrainingProgram $program, array $employeeIds, ?User $by): void
    {
        $users = Employee::query()->whereIn('id', $employeeIds)->with('user')->get()
            ->map(fn (Employee $e): ?User => $e->user)
            ->filter(fn (?User $u): bool => $u !== null && $u->is_active && $u->id !== $by?->id);

        foreach ($users as $user) {
            Notifier::toUser(
                $user,
                "You’re enrolled in {$program->name}",
                trim(($program->provider ? $program->provider.', ' : '').TrainingSessions::window($program).'. Your sessions are on My training.'),
                '/training/me',
                'info',
                'training',
                $by,
            );
        }
    }
}
