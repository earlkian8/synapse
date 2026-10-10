<?php

namespace App\Support\Training;

use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Employees putting themselves on a program (ADR 0074), and HR deciding the
 * requests — one path for the screens and the assistant.
 *
 * A program says who may join (`enrollment_mode`): on an **open** program an
 * employee takes a seat at once while seats remain; on an **approval** program
 * they ask, and the request waits — taking no seat — until HR approves it (a
 * seat is checked then) or declines it, with a note. A **closed** program is
 * filled by HR alone. Nobody joins a program that is over or archived.
 *
 * Leaving is the person's own while it costs nobody anything: a request can be
 * withdrawn at any time, and a seat given up until the program starts and before
 * any attendance is taken. After that, HR takes them off the roster.
 *
 * Seats are counted under a lock on the program row, so two people taking the
 * last seat at the same moment cannot both get it.
 */
class TrainingSelfService
{
    /**
     * Join an open program, or ask to join one that needs approval.
     *
     * @throws TrainingException
     */
    public function join(TrainingProgram $program, Employee $employee, User $by, string $channel = ''): TrainingEnrollment
    {
        if ($program->enrollment_mode === 'closed') {
            throw new TrainingException('HR enrolls people in this program — ask them to add you.');
        }

        if (! $program->acceptsSelfEnrollment()) {
            throw new TrainingException('This program is over, so it is no longer taking people.');
        }

        if ($employee->employment_status !== 'active') {
            throw new TrainingException('Only active employees can join training.');
        }

        $enrollment = DB::transaction(function () use ($program, $employee): TrainingEnrollment {
            $locked = TrainingProgram::query()->lockForUpdate()->findOrFail($program->id);

            $existing = TrainingEnrollment::query()
                ->where('training_program_id', $program->id)
                ->where('employee_id', $employee->id)
                ->first();

            if ($existing !== null) {
                throw new TrainingException(match ($existing->status) {
                    'requested' => 'You have already asked to join — HR will decide.',
                    'declined' => 'HR declined your request to join this program.',
                    default => 'You are already on this program.',
                });
            }

            $direct = $locked->enrollment_mode === 'open';

            if ($direct && self::seatsLeft($locked) === 0) {
                throw new TrainingException('This program is full.');
            }

            return $locked->enrollments()->create([
                'employee_id' => $employee->id,
                'status' => $direct ? 'enrolled' : 'requested',
            ]);
        });

        ActivityLogger::log(
            event: 'created',
            description: ($enrollment->status === 'enrolled'
                ? "{$employee->full_name} joined {$program->name}"
                : "{$employee->full_name} asked to join {$program->name}").$channel,
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        if ($enrollment->status === 'requested') {
            Notifier::toPermission(
                'training.manage',
                "Request to join {$program->name}",
                "{$employee->full_name} asked to join. Approve or decline it on the program’s roster.",
                '/training/'.$program->hashid,
                'info',
                'training',
                $by,
                except: [$by->id],
            );
        }

        return $enrollment;
    }

    /**
     * Withdraw one's own request, or give up one's own seat before the program
     * starts.
     *
     * @throws TrainingException
     */
    public function withdraw(TrainingEnrollment $enrollment, string $channel = ''): void
    {
        $enrollment->loadMissing(['program', 'employee']);
        $program = $enrollment->program;

        $leaving = match (true) {
            $enrollment->status === 'requested' => 'withdrew their request to join',
            $enrollment->status === 'enrolled' && $program?->status() === 'upcoming' && ! $enrollment->attendances()->exists() => 'left',
            $enrollment->status === 'enrolled' => throw new TrainingException('The program has started — ask HR to take you off the roster.'),
            default => throw new TrainingException('There is nothing to withdraw from.'),
        };

        $enrollment->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "{$enrollment->employee?->full_name} {$leaving} {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );
    }

    /**
     * Approve a request: the person takes a seat, if one is left.
     *
     * @throws TrainingException
     */
    public function approve(TrainingEnrollment $enrollment, User $by, string $channel = ''): TrainingEnrollment
    {
        $this->assertPending($enrollment);

        DB::transaction(function () use ($enrollment, $by): void {
            $program = TrainingProgram::query()->lockForUpdate()->findOrFail($enrollment->training_program_id);

            if (self::seatsLeft($program) === 0) {
                throw new TrainingException('The program is full — free a seat or raise its capacity first.');
            }

            $enrollment->update([
                'status' => 'enrolled',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => null,
            ]);
        });

        $this->decided($enrollment, $by, approved: true, channel: $channel);

        return $enrollment;
    }

    /**
     * Decline a request, with an optional note the person is shown.
     *
     * @throws TrainingException
     */
    public function decline(TrainingEnrollment $enrollment, User $by, ?string $note, string $channel = ''): TrainingEnrollment
    {
        $this->assertPending($enrollment);

        $enrollment->update([
            'status' => 'declined',
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => filled($note) ? trim((string) $note) : null,
        ]);

        $this->decided($enrollment, $by, approved: false, channel: $channel);

        return $enrollment;
    }

    /**
     * Seats left on a program: null when it is uncapped.
     */
    public static function seatsLeft(TrainingProgram $program): ?int
    {
        if ($program->capacity === null) {
            return null;
        }

        return max(0, $program->capacity - $program->enrollments()->active()->count());
    }

    /**
     * @throws TrainingException
     */
    private function assertPending(TrainingEnrollment $enrollment): void
    {
        if ($enrollment->status !== 'requested') {
            throw new TrainingException('That request has already been decided.');
        }

        if ($enrollment->program()->doesntExist()) {
            throw new TrainingException('That program has been archived.');
        }
    }

    private function decided(TrainingEnrollment $enrollment, User $by, bool $approved, string $channel): void
    {
        $enrollment->loadMissing(['program', 'employee.user']);
        $program = $enrollment->program;
        $who = $enrollment->employee?->full_name;

        ActivityLogger::log(
            event: 'updated',
            description: ($approved ? "Approved {$who}'s request to join" : "Declined {$who}'s request to join")." {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );

        $user = $enrollment->employee?->user;

        if ($user === null || ! $user->is_active || $user->id === $by->id) {
            return;
        }

        Notifier::toUser(
            $user,
            $approved ? "You’re in: {$program?->name}" : "Your request to join {$program?->name} was declined",
            $approved
                ? 'Your request to join was approved. Your sessions are on My training.'
                : ($enrollment->decision_note !== null ? 'HR’s note: '.$enrollment->decision_note : 'Ask HR if you’d like to know why.'),
            '/training/me',
            $approved ? 'success' : 'warning',
            'training',
            $by,
        );
    }
}
