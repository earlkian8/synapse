<?php

namespace App\Support\Training;

use App\Models\TrainingAttendance;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A program's sessions and the attendance taken at them (ADR 0074), in one
 * place for the Training screens and the assistant.
 *
 * - A session sits inside the program's dates (an open side takes any date),
 *   ends after it starts, and is on the organisation's wall clock. Adding one can
 *   repeat it on every day (or every weekday) up to a date —
 *   {@see self::MAX_PER_REQUEST} at a time, {@see self::MAX_PER_PROGRAM} in all.
 * - Attendance is taken from the session's day on, for the people holding a seat
 *   (enrolled or completed). Present and late count as attended, absent against,
 *   and excused leaves the session out of the person's rate.
 */
class TrainingSessions
{
    /** How many sessions one "repeat" may create. */
    public const MAX_PER_REQUEST = 60;

    /** How many sessions a program may have. */
    public const MAX_PER_PROGRAM = 200;

    /**
     * Add a session, or one per day (weekdays only, if asked) from its date to
     * `repeat_until`.
     *
     * @param  array{title?: string|null, session_date: string, starts_at: string, ends_at: string, location?: string|null, notes?: string|null, repeat_until?: string|null, weekdays_only?: bool|null}  $data
     * @return Collection<int, TrainingSession>
     *
     * @throws TrainingException
     */
    public function create(TrainingProgram $program, array $data, string $channel = ''): Collection
    {
        $this->assertTimes($data['starts_at'], $data['ends_at']);

        $dates = $this->dates($data['session_date'], $data['repeat_until'] ?? null, (bool) ($data['weekdays_only'] ?? false));

        foreach ([$dates->first(), $dates->last()] as $date) {
            $this->assertInWindow($program, $date, $dates->count() > 1 && $date === $dates->last() ? 'repeat_until' : 'session_date');
        }

        if ($program->sessions()->count() + $dates->count() > self::MAX_PER_PROGRAM) {
            throw new TrainingException('A program can have at most '.self::MAX_PER_PROGRAM.' sessions.', 'repeat_until');
        }

        $sessions = DB::transaction(fn (): Collection => $dates->map(fn (CarbonImmutable $date): TrainingSession => $program->sessions()->create([
            'title' => $this->clean($data['title'] ?? null),
            'session_date' => $date->toDateString(),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'location' => $this->clean($data['location'] ?? null),
            'notes' => $this->clean($data['notes'] ?? null),
        ])));

        ActivityLogger::log(
            event: 'created',
            description: ($sessions->count() === 1
                ? 'Scheduled a session of '.$program->name.' on '.$sessions->first()->session_date->format('M j, Y')
                : "Scheduled {$sessions->count()} sessions of {$program->name}").$channel,
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $sessions;
    }

    /**
     * @param  array{title?: string|null, session_date: string, starts_at: string, ends_at: string, location?: string|null, notes?: string|null}  $data
     *
     * @throws TrainingException
     */
    public function update(TrainingSession $session, array $data, string $channel = ''): TrainingSession
    {
        $program = $session->program;
        $this->assertTimes($data['starts_at'], $data['ends_at']);
        $this->assertInWindow($program, CarbonImmutable::parse($data['session_date']), 'session_date');

        // Attendance taken on a day that has not come is not attendance.
        if ($data['session_date'] > OrganizationClock::today() && $session->attendances()->exists()) {
            throw new TrainingException('Attendance has been taken at this session, so it cannot move to a later day.', 'session_date');
        }

        $session->update([
            'title' => $this->clean($data['title'] ?? null),
            'session_date' => $data['session_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'location' => $this->clean($data['location'] ?? null),
            'notes' => $this->clean($data['notes'] ?? null),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated the {$session->session_date->format('M j, Y')} session of {$program->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program->name,
        );

        return $session;
    }

    /**
     * Cancel a session; the attendance taken at it goes with it.
     */
    public function delete(TrainingSession $session, string $channel = ''): void
    {
        $program = $session->program;
        $day = $session->session_date->format('M j, Y');
        $session->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Cancelled the {$day} session of {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );
    }

    /**
     * Take attendance at a session: a status per enrollment, or null to clear a
     * mark. Only people holding a seat on this program can be marked.
     *
     * @param  array<int, string|null>  $marks  enrollment id => present|late|absent|excused|null
     * @return int How many marks were set or cleared.
     *
     * @throws TrainingException
     */
    public function mark(TrainingSession $session, array $marks, ?User $by, string $channel = ''): int
    {
        if (! $session->isHeld()) {
            throw new TrainingException('Attendance can be taken from the day of the session.');
        }

        $seated = TrainingEnrollment::query()
            ->where('training_program_id', $session->training_program_id)
            ->active()
            ->pluck('id')
            ->flip();

        $strangers = collect(array_keys($marks))->reject(fn (int $id): bool => $seated->has($id));

        if ($strangers->isNotEmpty()) {
            throw new TrainingException('Attendance can only be taken for people enrolled in this program.');
        }

        $changed = DB::transaction(function () use ($session, $marks, $by): int {
            $changed = 0;

            foreach ($marks as $enrollmentId => $status) {
                if ($status === null) {
                    $changed += $session->attendances()->where('training_enrollment_id', $enrollmentId)->delete();

                    continue;
                }

                $session->attendances()->updateOrCreate(
                    ['training_enrollment_id' => $enrollmentId],
                    ['status' => $status, 'marked_by' => $by?->id],
                );
                $changed++;
            }

            return $changed;
        });

        $program = $session->program;

        ActivityLogger::log(
            event: 'updated',
            description: "Took attendance for {$changed} ".Str::plural('person', $changed)." at the {$session->session_date->format('M j, Y')} session of {$program?->name}{$channel}",
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );

        return $changed;
    }

    /**
     * Each enrollment's attendance on a program: sessions attended (present or
     * late), sessions counted (marked, excused left out), and the rate.
     *
     * @return array<int, array{attended: int, counted: int, rate: int|null}>
     */
    public static function rates(TrainingProgram $program): array
    {
        return TrainingAttendance::query()
            ->whereHas('session', fn ($query) => $query->where('training_program_id', $program->id))
            ->get(['training_enrollment_id', 'status'])
            ->groupBy('training_enrollment_id')
            ->map(fn (Collection $marks): array => self::rate($marks->pluck('status')))
            ->all();
    }

    /**
     * Attended / counted from a list of statuses.
     *
     * @param  Collection<int, string>  $statuses
     * @return array{attended: int, counted: int, rate: int|null}
     */
    public static function rate(Collection $statuses): array
    {
        $attended = $statuses->filter(fn (string $s): bool => in_array($s, TrainingAttendance::ATTENDED, true))->count();
        $counted = $statuses->reject(fn (string $s): bool => $s === 'excused')->count();

        return [
            'attended' => $attended,
            'counted' => $counted,
            'rate' => $counted === 0 ? null : (int) round($attended / $counted * 100),
        ];
    }

    /**
     * The session days a repeat makes, earliest first.
     *
     * @return Collection<int, CarbonImmutable>
     *
     * @throws TrainingException
     */
    private function dates(string $first, ?string $until, bool $weekdaysOnly): Collection
    {
        $start = CarbonImmutable::parse($first)->startOfDay();

        if ($until === null || $until === '' || $until === $first) {
            return collect([$start]);
        }

        $end = CarbonImmutable::parse($until)->startOfDay();

        if ($end->lt($start)) {
            throw new TrainingException('Repeat until a day after the first session.', 'repeat_until');
        }

        $dates = collect();

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if ($weekdaysOnly && $day->isWeekend()) {
                continue;
            }

            if ($dates->count() === self::MAX_PER_REQUEST) {
                throw new TrainingException('That makes more than '.self::MAX_PER_REQUEST.' sessions — repeat until an earlier day.', 'repeat_until');
            }

            $dates->push($day);
        }

        if ($dates->isEmpty()) {
            throw new TrainingException('Those days are all on a weekend.', 'repeat_until');
        }

        return $dates;
    }

    /**
     * @throws TrainingException
     */
    private function assertTimes(string $starts, string $ends): void
    {
        if (substr($ends, 0, 5) <= substr($starts, 0, 5)) {
            throw new TrainingException('A session ends after it starts.', 'ends_at');
        }
    }

    /**
     * @throws TrainingException
     */
    private function assertInWindow(TrainingProgram $program, CarbonImmutable $date, string $field): void
    {
        if ($program->covers($date)) {
            return;
        }

        throw new TrainingException('Sessions fall within the program’s dates ('.self::window($program).').', $field);
    }

    /** The program's dates in words, "Jun 15 – Jun 19, 2026". */
    public static function window(TrainingProgram $program): string
    {
        $start = $program->start_date?->format('M j, Y');
        $end = $program->end_date?->format('M j, Y');

        return match (true) {
            $start !== null && $end !== null => "{$start} – {$end}",
            $start !== null => "from {$start}",
            $end !== null => "until {$end}",
            default => 'no dates set',
        };
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
