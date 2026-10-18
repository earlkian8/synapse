<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Support\Events\EventCalendar;
use App\Support\OrganizationClock;
use App\Support\Training\TrainingCertificates;
use App\Support\Training\TrainingSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Training as the person taking it sees it (ADRs 0074, 0094): their programs,
 * their sessions coming up, their certificates, the programs they may join, and
 * one program with their own place on it. My training and Browse on the web and
 * the app's training screens all read it, so the phone and the browser show the
 * same thing.
 *
 * Everything here is the person's own, or a program open to them. A program is
 * shown as a participant sees it: never its budget, costs or HR's AI notes.
 */
final class MyTraining
{
    /** How far ahead "coming up" looks. */
    public const AHEAD_DAYS = 120;

    /**
     * The person's enrollments, requests included, newest first.
     *
     * @return Collection<int, TrainingEnrollment>
     */
    public function enrollments(?Employee $employee): Collection
    {
        if ($employee === null) {
            return collect();
        }

        return TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereHas('program')
            ->with(['program', 'certificate:id,training_enrollment_id,certificate_no,expiry_date', 'attendances:id,training_enrollment_id,status'])
            ->latest('id')
            ->get();
    }

    /**
     * The sessions coming up on the programs the person holds a seat on.
     *
     * @param  Collection<int, TrainingEnrollment>  $enrollments
     * @return Collection<int, TrainingSession>
     */
    public function upcomingSessions(Collection $enrollments): Collection
    {
        $seated = $enrollments->filter(fn (TrainingEnrollment $e): bool => in_array($e->status, TrainingEnrollment::SEATED_STATUSES, true));

        if ($seated->isEmpty()) {
            return collect();
        }

        return TrainingSession::query()
            ->whereIn('training_program_id', $seated->pluck('training_program_id'))
            ->ofLivePrograms()
            ->whereBetween('session_date', [OrganizationClock::today(), now()->addDays(self::AHEAD_DAYS)->toDateString()])
            ->with('program:id,name,provider')
            ->chronological()
            ->get();
    }

    /**
     * The person's training certificates, newest first.
     *
     * @return Collection<int, EmployeeCertification>
     */
    public function certificates(?Employee $employee): Collection
    {
        return $employee === null
            ? collect()
            : TrainingCertificates::register()->where('employee_id', $employee->id)->latest('issued_date')->latest('id')->get();
    }

    /**
     * The programs open to sign-ups that are not over, soonest first — the
     * order someone choosing what to join reads them in — and undated ones last.
     *
     * @return Collection<int, TrainingProgram>
     */
    public function catalog(): Collection
    {
        return TrainingProgram::query()
            ->where('enrollment_mode', '!=', 'closed')
            ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
            ->withCount('sessions')
            ->orderByRaw('start_date is null')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (TrainingProgram $p): bool => $p->acceptsSelfEnrollment())
            ->values();
    }

    /**
     * Where the person stands on each of some programs, by program id.
     *
     * @param  Collection<int, TrainingProgram>  $programs
     * @return Collection<int, TrainingEnrollment>
     */
    public function standings(?Employee $employee, Collection $programs): Collection
    {
        return $employee === null ? collect() : TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereIn('training_program_id', $programs->pluck('id'))
            ->get(['id', 'training_program_id', 'status', 'decision_note'])
            ->keyBy('training_program_id');
    }

    /**
     * Whether the person may see a program here: it takes sign-ups, or they
     * already have a place on it (or asked for one).
     */
    public function visible(TrainingProgram $program, ?Employee $employee): bool
    {
        return $program->acceptsSelfEnrollment()
            || ($employee !== null && $program->enrollments()->where('employee_id', $employee->id)->exists());
    }

    /**
     * One program as the person sees it: what it is, every session with their
     * own mark on it, their enrollment, and whether they can join.
     *
     * @return array<string, mixed>
     */
    public function program(TrainingProgram $program, ?Employee $employee): array
    {
        $program->loadCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active(), 'sessions']);

        $enrollment = $employee === null ? null : TrainingEnrollment::query()
            ->where('training_program_id', $program->id)
            ->where('employee_id', $employee->id)
            ->with(['program', 'certificate:id,training_enrollment_id,certificate_no,expiry_date', 'attendances:id,training_enrollment_id,training_session_id,status'])
            ->first();

        $marks = $enrollment?->attendances->pluck('status', 'training_session_id') ?? collect();
        $listing = self::listing($program);

        return [
            ...$listing,
            'sessions' => $program->sessions()->chronological()->get()->map(fn (TrainingSession $s): array => [
                ...self::sitting($s),
                'is_held' => $s->isHeld(),
                'mark' => $marks->get($s->id),
            ])->values()->all(),
            'mine' => $enrollment === null ? null : self::enrollment($enrollment),
            'join' => match (true) {
                $employee === null, $enrollment !== null, ! $program->acceptsSelfEnrollment() => null,
                $program->enrollment_mode === 'approval' => 'ask',
                $listing['is_full'] => 'full',
                default => 'join',
            },
        ];
    }

    /**
     * A program as a participant may see it, for Browse and the app.
     *
     * @return array<string, mixed>
     */
    public static function listing(TrainingProgram $program): array
    {
        $capacity = $program->capacity === null ? null : (int) $program->capacity;
        $taken = (int) ($program->active_enrollments_count ?? $program->enrollments()->active()->count());

        return [
            'id' => $program->id,
            'hashid' => $program->hashid,
            'name' => $program->name,
            'description' => $program->description,
            'provider' => $program->provider,
            'start_date' => $program->start_date?->toDateString(),
            'end_date' => $program->end_date?->toDateString(),
            'capacity' => $capacity,
            'status' => $program->status(),
            'enrollment_mode' => $program->enrollment_mode ?? 'closed',
            'issues_certificate' => (bool) $program->issues_certificate,
            'certificate_validity_months' => $program->certificate_validity_months === null ? null : (int) $program->certificate_validity_months,
            'seats_remaining' => $capacity === null ? null : max(0, $capacity - $taken),
            'is_full' => $capacity !== null && $taken >= $capacity,
            'sessions_count' => (int) ($program->sessions_count ?? 0),
        ];
    }

    /**
     * One of the person's enrollments: how it is going, and whether they may
     * still leave it.
     *
     * @return array<string, mixed>
     */
    public static function enrollment(TrainingEnrollment $e): array
    {
        return [
            'id' => $e->id,
            'status' => $e->status,
            'score' => $e->score === null ? null : (float) $e->score,
            'completed_at' => $e->completed_at?->toDateString(),
            'requested_on' => $e->created_at?->toDateString(),
            'decision_note' => $e->status === 'declined' ? $e->decision_note : null,
            'attendance' => TrainingSessions::rate($e->attendances->pluck('status')),
            'can_withdraw' => $e->status === 'requested'
                || ($e->status === 'enrolled' && $e->program->status() === 'upcoming' && $e->attendances->isEmpty()),
            'certificate_id' => $e->certificate?->id,
            'program' => [
                'hashid' => $e->program->hashid,
                'name' => $e->program->name,
                'provider' => $e->program->provider,
                'description' => $e->program->description,
                'start_date' => $e->program->start_date?->toDateString(),
                'end_date' => $e->program->end_date?->toDateString(),
                'status' => $e->program->status(),
            ],
        ];
    }

    /**
     * A session coming up, with the program it belongs to.
     *
     * @return array<string, mixed>
     */
    public static function session(TrainingSession $s): array
    {
        return [
            ...self::sitting($s),
            'program' => ['hashid' => $s->program?->hashid, 'name' => $s->program?->name, 'provider' => $s->program?->provider],
        ];
    }

    /**
     * Whether the person holds a seat on the session's program — what lets them
     * download it to a calendar.
     */
    public function holdsSeat(TrainingSession $session, ?Employee $employee): bool
    {
        return $employee !== null && $session->program !== null && TrainingEnrollment::query()
            ->where('training_program_id', $session->training_program_id)
            ->where('employee_id', $employee->id)
            ->active()
            ->exists();
    }

    /**
     * A session as a calendar file: its contents and a file name.
     *
     * @return array{0: string, 1: string}
     */
    public static function calendarFile(TrainingSession $session): array
    {
        $slug = Str::slug((string) $session->program?->name) ?: 'training';

        return [EventCalendar::document([], sessions: [$session]), "{$slug}-{$session->session_date->toDateString()}.ics"];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sitting(TrainingSession $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'session_date' => $s->session_date->toDateString(),
            'starts_at' => $s->startTime(),
            'ends_at' => $s->endTime(),
            'location' => $s->location,
            'notes' => $s->notes,
        ];
    }
}
