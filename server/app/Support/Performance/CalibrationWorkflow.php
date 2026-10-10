<?php

namespace App\Support\Performance;

use App\Models\CalibrationAdjustment;
use App\Models\CalibrationSession;
use App\Models\Department;
use App\Models\EvaluationPeriod;
use App\Models\PerformanceEvaluation;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Calibration sessions (ADR 0073) — opening one over a cycle (or some of its
 * departments), moving ratings in it with a reason, and completing or cancelling
 * it. Screens and assistant both come through here; refusals are
 * {@see AppraisalException}s.
 *
 * A move changes the **official rating** (`result_band` / `result_label`) to
 * another band of the appraisal's own rating model, keeping what the scorecard
 * gave in `scored_band` / `scored_label`; attainment and the 1–5 index are
 * untouched, so the models keep learning from what was scored. While a session
 * is open, appraisals submitted inside it are held back from the employee
 * ({@see AppraisalSharing}); completing or cancelling it shares them.
 */
class CalibrationWorkflow
{
    public function __construct(private readonly AppraisalSharing $sharing) {}

    /**
     * Open a session. Two open sessions in one cycle never cover the same
     * people, so a rating is only ever being calibrated in one room.
     *
     * @param  list<int>|null  $departmentIds  Null for the whole cycle.
     * @param  iterable<int, User>  $participants
     *
     * @throws AppraisalException
     */
    public function open(
        EvaluationPeriod $period,
        string $name,
        ?array $departmentIds,
        ?string $scheduledFor,
        ?string $notes,
        iterable $participants,
        ?User $by,
        string $channel = '',
    ): CalibrationSession {
        if ($period->status === 'draft') {
            throw new AppraisalException('A cycle is calibrated once it is open and has appraisals in it.');
        }

        $departmentIds = $departmentIds === null || $departmentIds === [] ? null : array_values(array_unique(array_map('intval', $departmentIds)));

        if ($clash = $this->overlapping($period, $departmentIds)) {
            throw new AppraisalException("“{$clash->name}” is already calibrating some of the same people in {$period->name}. Complete or cancel it first, or pick other departments.");
        }

        $session = DB::transaction(function () use ($period, $name, $departmentIds, $scheduledFor, $notes, $participants, $by): CalibrationSession {
            $session = CalibrationSession::create([
                'evaluation_period_id' => $period->id,
                'name' => trim($name),
                'scheduled_for' => $scheduledFor ?: null,
                'department_ids' => $departmentIds,
                'notes' => $this->text($notes),
                'facilitator_id' => $by?->id,
                'status' => 'open',
            ]);

            $session->participants()->sync(collect($participants)->pluck('id')->all());

            return $session;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Opened the calibration session “{$session->name}” ({$period->name}){$channel}",
            subject: $session,
            logName: 'performance',
            subjectLabel: $session->name,
        );

        $this->invite($session, collect($participants), $by);

        return $session;
    }

    /**
     * Rename, reschedule or re-note an open session, and change who takes part
     * (newcomers are told).
     *
     * @param  iterable<int, User>  $participants
     *
     * @throws AppraisalException
     */
    public function update(CalibrationSession $session, string $name, ?string $scheduledFor, ?string $notes, iterable $participants, ?User $by): void
    {
        $this->assertOpen($session);

        $before = $session->participants()->pluck('users.id')->all();
        $participants = collect($participants);

        $session->update([
            'name' => trim($name),
            'scheduled_for' => $scheduledFor ?: null,
            'notes' => $this->text($notes),
        ]);

        $session->participants()->sync($participants->pluck('id')->all());

        $this->invite($session, $participants->reject(fn (User $u): bool => in_array($u->id, $before, true)), $by);

        ActivityLogger::log(
            event: 'updated',
            description: "Updated the calibration session “{$session->name}”",
            subject: $session,
            logName: 'performance',
            subjectLabel: $session->name,
        );
    }

    /**
     * Move one appraisal's rating to another band of its own model, with why.
     * Moving it back to what the scorecard gave clears the calibration.
     *
     * @throws AppraisalException
     */
    public function adjust(CalibrationSession $session, PerformanceEvaluation $evaluation, string $bandKey, string $reason, ?User $by, string $channel = ''): CalibrationAdjustment
    {
        $this->assertOpen($session);

        $evaluation->loadMissing('employee.user', 'period');

        if ($evaluation->evaluation_period_id !== $session->evaluation_period_id
            || ! $session->covers($evaluation->employee?->department_id === null ? null : (int) $evaluation->employee->department_id)) {
            throw new AppraisalException('That appraisal isn’t part of this session.');
        }

        if ($evaluation->isAbout($by)) {
            throw new AppraisalException('You can’t calibrate your own rating.');
        }

        if ($evaluation->status === 'draft') {
            throw new AppraisalException('Only a submitted appraisal can be calibrated. This one is still in progress.');
        }

        if ($evaluation->status === 'acknowledged') {
            throw new AppraisalException('The employee has acknowledged this appraisal, so its rating is final.');
        }

        $band = collect($evaluation->bandList())->firstWhere('key', $bandKey);

        if ($band === null) {
            throw new AppraisalException('That band isn’t part of this appraisal’s rating model.');
        }

        if ($band['key'] === $evaluation->result_band) {
            throw new AppraisalException("It is already rated “{$band['label']}”.");
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new AppraisalException('Say why the rating moves — the reason stays on the record.');
        }

        $adjustment = DB::transaction(function () use ($session, $evaluation, $band, $reason, $by): CalibrationAdjustment {
            $adjustment = CalibrationAdjustment::create([
                'calibration_session_id' => $session->id,
                'performance_evaluation_id' => $evaluation->id,
                'from_band' => $evaluation->result_band,
                'from_label' => $evaluation->result_label,
                'to_band' => $band['key'],
                'to_label' => $band['label'],
                'reason' => $reason,
                'adjusted_by' => $by?->id,
            ]);

            $scoredBand = $evaluation->scored_band ?? $evaluation->result_band;
            $scoredLabel = $evaluation->scored_label ?? $evaluation->result_label;
            $back = $band['key'] === $scoredBand;

            $evaluation->forceFill([
                'result_band' => $band['key'],
                'result_label' => $band['label'],
                'scored_band' => $back ? null : $scoredBand,
                'scored_label' => $back ? null : $scoredLabel,
                'calibrated_at' => $back ? null : now(),
            ])->save();

            return $adjustment;
        });

        $name = $evaluation->employee?->full_name;

        ActivityLogger::log(
            event: 'updated',
            description: "Calibrated {$name}'s rating from “{$adjustment->from_label}” to “{$adjustment->to_label}” in “{$session->name}”{$channel}",
            subject: $evaluation,
            logName: 'performance',
            subjectLabel: $name,
        );

        // Someone who can already read the result hears that it moved.
        if ($evaluation->isShared()) {
            $this->sharing->tellEmployee(
                $evaluation,
                'Your appraisal rating was updated',
                "Calibration moved your {$evaluation->period?->name} rating to “{$band['label']}”.",
            );
        }

        return $adjustment;
    }

    /**
     * Close the session: its moves stand, and every appraisal it was holding is
     * shared with its employee.
     *
     * @throws AppraisalException
     */
    public function complete(CalibrationSession $session, string $channel = ''): int
    {
        $this->assertOpen($session);

        $session->update(['status' => 'completed', 'completed_at' => now()]);
        $released = $this->sharing->release($session);

        ActivityLogger::log(
            event: 'updated',
            description: "Completed the calibration session “{$session->name}”"
                .($released > 0 ? " and shared {$released} ".str('appraisal')->plural($released) : '').$channel,
            subject: $session,
            logName: 'performance',
            subjectLabel: $session->name,
        );

        return $released;
    }

    /**
     * Call a session off before anything was moved in it. The appraisals it was
     * holding are shared as they stand.
     *
     * @throws AppraisalException
     */
    public function cancel(CalibrationSession $session, string $channel = ''): int
    {
        $this->assertOpen($session);

        if ($session->adjustments()->exists()) {
            throw new AppraisalException('Ratings have been moved in this session, so it can’t be cancelled. Complete it instead.');
        }

        $session->update(['status' => 'cancelled']);
        $released = $this->sharing->release($session);

        ActivityLogger::log(
            event: 'updated',
            description: "Cancelled the calibration session “{$session->name}”{$channel}",
            subject: $session,
            logName: 'performance',
            subjectLabel: $session->name,
        );

        return $released;
    }

    /**
     * An open session in the cycle that would cover some of the same people.
     *
     * @param  list<int>|null  $departmentIds
     */
    public function overlapping(EvaluationPeriod $period, ?array $departmentIds, ?int $except = null): ?CalibrationSession
    {
        return CalibrationSession::query()
            ->open()
            ->where('evaluation_period_id', $period->id)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->get()
            ->first(function (CalibrationSession $other) use ($departmentIds): bool {
                $theirs = $other->departmentScope();

                return $departmentIds === null || $theirs === null || array_intersect($departmentIds, $theirs) !== [];
            });
    }

    /**
     * What the session covers, in words — "Engineering, Sales" or "the whole
     * cycle".
     */
    public static function scopeLabel(CalibrationSession $session): string
    {
        $ids = $session->departmentScope();

        if ($ids === null) {
            return 'Everyone in the cycle';
        }

        return Department::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') ?: 'No departments';
    }

    /**
     * @throws AppraisalException
     */
    private function assertOpen(CalibrationSession $session): void
    {
        if (! $session->isOpen()) {
            throw new AppraisalException("This session is {$session->status}; its ratings can no longer change.");
        }
    }

    /**
     * Tell new participants they are in the session.
     *
     * @param  iterable<int, User>  $participants
     */
    private function invite(CalibrationSession $session, iterable $participants, ?User $by): void
    {
        $when = $session->scheduled_for ? ' on '.$session->scheduled_for->format('M j') : '';

        foreach ($participants as $user) {
            if ($user->id === $by?->id || ! $user->is_active) {
                continue;
            }

            Notifier::toUser(
                $user,
                'Calibration session: '.$session->name,
                ($by?->full_name ?? 'HR')." added you to a calibration session{$when}.",
                '/performance/calibration/'.$session->hashid,
                'info',
                'performance',
                $by,
            );
        }
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
