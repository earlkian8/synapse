<?php

namespace App\Support\Discipline;

use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\Employee;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Files\Uploads;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Every step of a disciplinary case (ADR 0105), and the due process that
 * governs them: the twin-notice rule (Labor Code Art. 292(b); DOLE D.O. 147-15).
 *
 * The rules it will not bend:
 *
 *  - **No decision without a notice to explain.** It names the specific
 *    charge and narrates the facts, and gives at least five calendar days to
 *    answer in writing.
 *  - **No decision before they could answer.** Either their explanation is in,
 *    or the time to give it has run out — and then the right is waived.
 *  - **A hearing they asked for in writing is held first,** or recorded as one
 *    they didn't attend.
 *  - **A decision says why**, every circumstance considered, upheld or not.
 *  - **Dismissal rests on a just cause** (Art. 297), never on a company rule
 *    alone; and a preventive suspension never runs past 30 days.
 *
 * Every step is written to the activity log, and the employee is told of each
 * notice. The employee's acknowledgement of a notice is kept as proof it was
 * served. A case is decided by somebody other than the employee it is about.
 */
class DisciplineWorkflow
{
    /**
     * File an incident report. Whoever decides cases is told.
     *
     * @param  array{employee_id: int, conduct_offense_id?: ?int, incident_date: string, incident_time?: ?string, location?: ?string, narrative: string, witnesses?: ?string}  $attributes
     */
    public function report(array $attributes, User $by): DisciplinaryCase
    {
        $case = DB::transaction(function () use ($attributes, $by): DisciplinaryCase {
            $case = new DisciplinaryCase([...$attributes, 'reported_by' => $by->id]);
            $case->reference = $this->nextReference(CarbonImmutable::parse($attributes['incident_date'])->year);
            $case->save();

            return $case;
        });

        $case->loadMissing('employee');
        $name = $case->employee?->full_name ?? 'an employee';

        $this->log('created', "Reported an incident about {$name} ({$case->reference})", $case);

        Notifier::toPermission(
            'discipline.manage',
            "Incident reported: {$name}",
            "{$case->reference}, ".$case->incident_date->format('M j, Y').'. Serve a notice to explain if it should go further.',
            route('discipline.cases.show', $case),
            'warning',
            'discipline',
            $by,
            except: array_filter([$by->id, $case->employee?->user_id]),
        );

        return $case;
    }

    /**
     * Correct what the report says, while nothing has been served on it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DisciplineException
     */
    public function update(DisciplinaryCase $case, array $attributes): DisciplinaryCase
    {
        if ($case->status !== 'reported') {
            throw new DisciplineException('The notice to explain has been served, so the report stands as it was. Add what you learned to the hearing notes or the decision.');
        }

        $case->update($attributes);

        $this->log('updated', "Updated the incident report {$case->reference}", $case, ['changes' => array_keys($case->getChanges())]);

        return $case;
    }

    public function attach(DisciplinaryCase $case, UploadedFile $file, User $by): DisciplinaryCaseAttachment
    {
        $attachment = $case->attachments()->create([
            'path' => $file->store('discipline', Uploads::DISK),
            'name' => mb_substr($file->getClientOriginalName() ?: 'evidence', 0, 200),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);

        $this->log('updated', "Added evidence to {$case->reference}: {$attachment->name}", $case);

        return $attachment;
    }

    public function detach(DisciplinaryCaseAttachment $attachment): void
    {
        $case = $attachment->case;
        Uploads::disk()->delete($attachment->path);
        $attachment->delete();

        $this->log('updated', "Removed evidence from {$case->reference}: {$attachment->name}", $case);
    }

    /**
     * Serve the notice to explain: the specific charge, the facts (the report's
     * narrative), and the day an answer is due — at least five calendar days
     * away. The employee is told.
     *
     * @throws DisciplineException
     */
    public function issueNotice(DisciplinaryCase $case, string $grounds, CarbonImmutable $replyDue, ?int $offenseId, User $by): void
    {
        if ($case->status !== 'reported') {
            throw new DisciplineException('A notice to explain has already been served on this case.');
        }

        $this->assertNotOwn($case, $by, 'serve a notice on yourself');

        $earliest = $this->today()->addDays(DisciplinaryCase::MIN_REPLY_DAYS);

        if ($replyDue->lt($earliest)) {
            throw new DisciplineException('Give at least '.DisciplinaryCase::MIN_REPLY_DAYS.' calendar days to answer: on or after '.$earliest->format('M j, Y').'.');
        }

        $case->forceFill([
            'conduct_offense_id' => $offenseId ?? $case->conduct_offense_id,
            'notice_grounds' => $grounds,
            'notice_issued_at' => now(),
            'notice_issued_by' => $by->id,
            'reply_due_on' => $replyDue->toDateString(),
            'status' => 'notice_issued',
        ])->save();

        $this->log('updated', "Served a notice to explain ({$case->reference}), an answer due ".$replyDue->format('M j, Y'), $case);

        $this->tellEmployee(
            $case,
            'You’ve been served a notice to explain',
            'Read it and answer in writing by '.$replyDue->format('M j, Y').'. You can ask for a hearing.',
            $by,
        );
    }

    /**
     * The employee says they received a notice — the notice to explain, or the
     * decision. Kept as proof of service; only the first time counts.
     *
     * @throws DisciplineException
     */
    public function acknowledge(DisciplinaryCase $case, string $notice): void
    {
        if ($notice === 'decision') {
            if ($case->decided_at === null) {
                throw new DisciplineException('There is no decision to acknowledge yet.');
            }

            if ($case->decision_received_at === null) {
                $case->forceFill(['decision_received_at' => now()])->save();
                $this->log('updated', "Acknowledged receipt of the decision on {$case->reference}", $case);
            }

            return;
        }

        if ($case->notice_issued_at === null) {
            throw new DisciplineException('No notice has been served on this case.');
        }

        if ($case->notice_received_at === null) {
            $case->forceFill(['notice_received_at' => now()])->save();
            $this->log('updated', "Acknowledged receipt of the notice to explain on {$case->reference}", $case);
        }
    }

    /**
     * The employee's written explanation — sent by them, or handed in on paper
     * and recorded by HR — and whether they ask for a hearing. Taken until the
     * case is decided, even after the due date: a late answer is still read.
     *
     * @throws DisciplineException
     */
    public function explain(DisciplinaryCase $case, string $explanation, bool $hearing, User $by): void
    {
        if (! in_array($case->status, ['notice_issued', 'explained', 'hearing'], true)) {
            throw new DisciplineException($case->status === 'reported'
                ? 'There is nothing to answer yet: no notice has been served.'
                : 'This case has been decided. The explanation can no longer be changed.');
        }

        $own = $by->id === $case->employee?->user_id;

        $case->forceFill([
            'explanation' => $explanation,
            'explained_at' => now(),
            'explanation_recorded_by' => $own ? null : $by->id,
            'hearing_requested' => $hearing || $case->hearing_requested,
            'notice_received_at' => $case->notice_received_at ?? now(),
            'status' => $case->status === 'hearing' ? 'hearing' : 'explained',
        ])->save();

        $this->log('updated', ($own ? 'Answered' : 'Recorded the written answer to')." the notice to explain on {$case->reference}".($hearing ? ', asking for a hearing' : ''), $case);

        if ($own) {
            Notifier::toPermission(
                'discipline.manage',
                "{$case->employee->full_name} answered {$case->reference}",
                $hearing ? 'They ask for a hearing. Schedule it before deciding.' : 'Read their explanation, then decide the case.',
                route('discipline.cases.show', $case),
                'info',
                'discipline',
                $by,
                except: [$by->id],
            );
        }
    }

    /**
     * Set a hearing or conference: when and where. The employee is told.
     *
     * @throws DisciplineException
     */
    public function scheduleHearing(DisciplinaryCase $case, CarbonImmutable $at, ?string $place, User $by): void
    {
        if (! in_array($case->status, ['notice_issued', 'explained', 'hearing'], true)) {
            throw new DisciplineException('Serve the notice to explain before setting a hearing.');
        }

        $case->forceFill([
            'hearing_at' => $at,
            'hearing_place' => $place,
            'hearing_held_at' => null,
            'hearing_attended' => null,
            'status' => 'hearing',
        ])->save();

        $local = OrganizationClock::local($at);

        $this->log('updated', "Set a hearing on {$case->reference} for ".$local->format('M j, Y g:i A'), $case);

        $this->tellEmployee(
            $case,
            'A hearing is set on your case',
            $local->format('l, M j, g:i A').($place ? " at {$place}" : '').'. You may bring a representative.',
            $by,
        );
    }

    /**
     * What happened at the hearing, and whether they came. A hearing they don't
     * attend is one they had the chance of.
     *
     * @throws DisciplineException
     */
    public function recordHearing(DisciplinaryCase $case, bool $attended, string $notes): void
    {
        if ($case->hearing_at === null || $case->status !== 'hearing') {
            throw new DisciplineException('Set the hearing before recording it.');
        }

        $case->forceFill([
            'hearing_held_at' => now(),
            'hearing_attended' => $attended,
            'hearing_notes' => $notes,
        ])->save();

        $this->log('updated', "Recorded the hearing on {$case->reference}".($attended ? '' : ', which the employee did not attend'), $case);
    }

    /**
     * Put the employee on preventive suspension while the case runs — for when
     * their presence is a serious and imminent threat to life or property — at
     * most 30 days. It is not a penalty.
     *
     * @throws DisciplineException
     */
    public function preventiveSuspension(DisciplinaryCase $case, CarbonImmutable $from, int $days, User $by): void
    {
        if (! $case->isOpen() || $case->notice_issued_at === null) {
            throw new DisciplineException('A preventive suspension goes with a served notice to explain, while the case is open.');
        }

        if ($days < 1 || $days > DisciplinaryCase::MAX_PREVENTIVE_DAYS) {
            throw new DisciplineException('A preventive suspension runs at most '.DisciplinaryCase::MAX_PREVENTIVE_DAYS.' days. Past that, take them back or pay them for the extension.');
        }

        $to = $from->addDays($days - 1);

        $case->forceFill(['preventive_from' => $from->toDateString(), 'preventive_to' => $to->toDateString()])->save();

        $this->log('updated', "Placed the employee on preventive suspension ({$case->reference}), ".$from->format('M j').' to '.$to->format('M j, Y'), $case);

        $this->tellEmployee(
            $case,
            'You’re on preventive suspension',
            'From '.$from->format('M j').' to '.$to->format('M j, Y').', while your case is heard. It is not a penalty.',
            $by,
        );
    }

    /**
     * How many times this would make it, and the penalty the code of conduct
     * gives for that: the employee's upheld cases of the same offense still
     * counting at the incident's date, plus this one.
     *
     * @return array{occurrence: int, penalty: ?string, days: ?int, prior: list<string>}
     */
    public function suggestion(DisciplinaryCase $case): array
    {
        $offense = $case->offense;

        if ($offense === null) {
            return ['occurrence' => 1, 'penalty' => null, 'days' => null, 'prior' => []];
        }

        $since = CarbonImmutable::parse($case->incident_date->toDateString())->subMonths($offense->reckoning_months);

        $prior = DisciplinaryCase::query()
            ->where('employee_id', $case->employee_id)
            ->where('conduct_offense_id', $offense->id)
            ->whereKeyNot($case->id)
            ->where('finding', 'upheld')
            ->whereIn('status', ['decided', 'closed'])
            ->where('decided_at', '>=', $since)
            ->orderBy('decided_at')
            ->pluck('reference')
            ->all();

        $occurrence = count($prior) + 1;
        $step = $offense->penaltyFor($occurrence);

        return ['occurrence' => $occurrence, 'penalty' => $step['action'] ?? null, 'days' => $step['days'] ?? null, 'prior' => $prior];
    }

    /**
     * Decide the case: the charge upheld or not, the penalty, and why. Serves
     * the notice of decision on the employee.
     *
     * @param  array{finding: string, penalty?: ?string, suspension_days?: ?int, penalty_from?: ?string, decision: string}  $decision
     *
     * @throws DisciplineException
     */
    public function decide(DisciplinaryCase $case, array $decision, User $by): void
    {
        $this->assertReadyToDecide($case);
        $this->assertNotOwn($case, $by, 'decide your own case');

        $upheld = $decision['finding'] === 'upheld';
        $penalty = $upheld ? ($decision['penalty'] ?? null) : null;

        if ($upheld && ! array_key_exists((string) $penalty, DisciplinaryCase::PENALTIES)) {
            throw new DisciplineException('Choose the penalty for an upheld charge.');
        }

        if ($penalty === 'dismissal' && ! ($case->offense?->isJustCause() ?? false)) {
            throw new DisciplineException('Dismissal needs a just cause under Article 297 of the Labor Code. Charge the case under an offense that rests on one, or choose a lesser penalty.');
        }

        $days = null;
        $from = null;
        $to = null;

        if ($penalty === 'suspension') {
            $days = (int) ($decision['suspension_days'] ?? 0);

            if ($days < 1 || $days > DisciplinaryCase::MAX_SUSPENSION_DAYS) {
                throw new DisciplineException('A suspension runs from 1 to '.DisciplinaryCase::MAX_SUSPENSION_DAYS.' days.');
            }

            $from = CarbonImmutable::parse($decision['penalty_from'] ?? $this->today()->addDay()->toDateString());
            $to = $from->addDays($days - 1);
        } elseif ($penalty === 'dismissal') {
            $from = CarbonImmutable::parse($decision['penalty_from'] ?? $this->today()->toDateString());
        }

        $suggestion = $this->suggestion($case);

        $case->forceFill([
            'finding' => $upheld ? 'upheld' : 'cleared',
            'penalty' => $penalty,
            'suggested_penalty' => $suggestion['penalty'],
            'occurrence' => $upheld ? $suggestion['occurrence'] : null,
            'suspension_days' => $days,
            'penalty_from' => $from?->toDateString(),
            'penalty_to' => $to?->toDateString(),
            'decision' => $decision['decision'],
            'decided_at' => now(),
            'decided_by' => $by->id,
            'status' => 'decided',
        ])->save();

        $words = $upheld ? DisciplinaryCase::PENALTIES[$penalty] : 'cleared';

        $this->log('updated', "Decided {$case->reference}: ".($upheld ? "upheld, {$words}" : 'the charge not upheld'), $case, [
            'finding' => $case->finding,
            'penalty' => $penalty,
            'suggested_penalty' => $suggestion['penalty'],
        ]);

        $this->tellEmployee(
            $case,
            'A decision was made on your case',
            $upheld ? "Read the notice of decision: {$words}." : 'The charge was not upheld. Read the notice of decision.',
            $by,
        );
    }

    /**
     * Close a decided case once its notice has been served and its penalty, if
     * any, has run.
     *
     * @throws DisciplineException
     */
    public function close(DisciplinaryCase $case): void
    {
        if ($case->status !== 'decided') {
            throw new DisciplineException('Only a decided case can be closed.');
        }

        $case->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->log('updated', "Closed {$case->reference}", $case);
    }

    /**
     * Drop a case before it is decided, with why. An employee already served is
     * told it was dropped.
     *
     * @throws DisciplineException
     */
    public function withdraw(DisciplinaryCase $case, string $reason, User $by): void
    {
        if (! $case->isOpen()) {
            throw new DisciplineException('A decided case can’t be withdrawn. It stays on the record as decided.');
        }

        $served = $case->notice_issued_at !== null;

        $case->forceFill([
            'status' => 'withdrawn',
            'withdrawn_reason' => $reason,
            'closed_at' => now(),
            'preventive_to' => $case->preventive_to !== null && $case->preventive_to->gt($this->today())
                ? $this->today()->toDateString()
                : $case->preventive_to?->toDateString(),
        ])->save();

        $this->log('updated', "Withdrew {$case->reference}", $case, ['reason' => $reason]);

        if ($served) {
            $this->tellEmployee($case, 'The case against you was withdrawn', 'Nothing further will come of it.', $by);
        }
    }

    // ── Code of conduct ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveOffense(?ConductOffense $offense, array $attributes): ConductOffense
    {
        $offense ??= new ConductOffense;
        $creating = ! $offense->exists;
        $offense->fill($attributes)->save();

        ActivityLogger::log(
            event: $creating ? 'created' : 'updated',
            description: ($creating ? 'Added' : 'Updated')." the offense “{$offense->code} {$offense->title}” in the code of conduct",
            subject: $offense,
            logName: 'discipline',
            subjectLabel: $offense->title,
        );

        return $offense;
    }

    public function removeOffense(ConductOffense $offense): void
    {
        $offense->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Removed the offense “{$offense->code} {$offense->title}” from the code of conduct",
            subject: $offense,
            logName: 'discipline',
            subjectLabel: $offense->title,
        );
    }

    // ── Guards and helpers ───────────────────────────────────────────────────

    /**
     * What has to have happened before a case can be decided, or why not.
     *
     * @return list<string> Empty when it can be decided.
     */
    public function blockers(DisciplinaryCase $case): array
    {
        $today = $this->today()->toDateString();
        $blockers = [];

        if ($case->notice_issued_at === null) {
            $blockers[] = 'Serve a notice to explain first. No case is decided without one.';

            return $blockers;
        }

        if (! $case->isOpen()) {
            return ['This case has already been '.$case->status.'.'];
        }

        if ($case->explained_at === null && ! $case->replyOverdue($today)) {
            $blockers[] = 'Wait for their written explanation until '.$case->reply_due_on?->format('M j, Y').'. They have at least '.DisciplinaryCase::MIN_REPLY_DAYS.' calendar days to answer.';
        }

        if ($case->hearing_requested && $case->hearing_at === null) {
            $blockers[] = 'They asked for a hearing in writing. Set it, hold it, then decide.';
        } elseif ($case->hearing_at !== null && $case->hearing_held_at === null) {
            $blockers[] = 'Record the hearing — what was said, and whether they came — before deciding.';
        }

        return $blockers;
    }

    /**
     * @throws DisciplineException
     */
    private function assertReadyToDecide(DisciplinaryCase $case): void
    {
        $blockers = $this->blockers($case);

        if ($blockers !== []) {
            throw new DisciplineException($blockers[0]);
        }
    }

    /**
     * @throws DisciplineException
     */
    private function assertNotOwn(DisciplinaryCase $case, User $by, string $what): void
    {
        $case->loadMissing('employee:id,user_id');

        if ($case->employee?->user_id !== null && $case->employee->user_id === $by->id) {
            throw new DisciplineException("You can’t {$what}. Somebody else in HR has to.");
        }
    }

    private function tellEmployee(DisciplinaryCase $case, string $title, string $body, User $by): void
    {
        $case->loadMissing('employee.user');
        $user = $case->employee instanceof Employee ? $case->employee->user : null;

        if ($user !== null) {
            Notifier::toUser($user, $title, "{$case->reference}. {$body}", route('discipline.me.show', $case), 'warning', 'discipline', $by);
        }
    }

    /**
     * "DC-2026-00007": the year of the incident and a running number in the
     * company.
     */
    private function nextReference(int $year): string
    {
        $prefix = "DC-{$year}-";

        $last = DisciplinaryCase::query()
            ->where('reference', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(OrganizationClock::today());
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, DisciplinaryCase $case, array $properties = []): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $case,
            properties: $properties,
            logName: 'discipline',
            subjectLabel: $case->employee?->full_name ?? $case->reference,
        );
    }
}
