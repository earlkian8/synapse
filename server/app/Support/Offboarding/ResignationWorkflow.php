<?php

namespace App\Support\Offboarding;

use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A resignation the employee files themselves (ADR 0076), from the web or the
 * phone: they file it, may withdraw it while it waits, and HR accepts it — it
 * becomes the exit, through {@see OffboardingWorkflow::start()} like any other —
 * or declines it with a note.
 *
 * One waits at a time per person, and none while an exit is already under way.
 * A last day closer than the Labor Code's month of notice is allowed: agreeing
 * to it is HR's call, and the screens say so. Refusals are an
 * {@see OffboardingException}, worded to be shown as they are. Everybody it
 * concerns is told: HR when one is filed or withdrawn, the employee when it is
 * decided.
 */
class ResignationWorkflow
{
    public function __construct(private readonly OffboardingWorkflow $offboarding) {}

    /**
     * File a resignation for an employee.
     *
     * @param  array{proposed_last_day: string, reason?: ?string}  $data
     *
     * @throws OffboardingException
     */
    public function file(Employee $employee, User $by, array $data, string $channel = ''): ResignationRequest
    {
        if (in_array($employee->employment_status, ['resigned', 'terminated'], true)) {
            throw new OffboardingException('Your employment has already ended.');
        }

        $case = $employee->offboardingCase()->first();

        if ($case !== null && $case->isActive()) {
            throw new OffboardingException('Your exit is already under way. HR is working through your clearance.');
        }

        if (ResignationRequest::query()->where('employee_id', $employee->id)->pending()->exists()) {
            throw new OffboardingException('You already have a resignation waiting for HR. Withdraw it first to change it.');
        }

        $resignation = ResignationRequest::create([
            'employee_id' => $employee->id,
            'user_id' => $by->id,
            'proposed_last_day' => $data['proposed_last_day'],
            'reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
            'status' => 'pending',
        ]);

        $lastDay = $resignation->proposed_last_day->format('M j, Y');
        $notice = $resignation->noticeDays();

        $this->log($resignation, 'created', "{$employee->full_name} filed a resignation (last day {$lastDay}){$channel}");

        Notifier::toPermission(
            'offboarding.manage',
            "{$employee->full_name} resigned",
            "Proposed last day: {$lastDay}, {$notice} ".str('day')->plural($notice).'’ notice'
                .($notice < ResignationRequest::NOTICE_DAYS ? ' (less than the 30 days the Labor Code asks for)' : '').'. Accept it to start their clearance, or decline it.',
            url: "/offboarding/resignations?open={$resignation->hashid}",
            level: 'warning',
            category: 'offboarding',
            actor: $by,
            except: [$by->id],
        );

        return $resignation;
    }

    /**
     * Take back a resignation that is still waiting.
     *
     * @throws OffboardingException
     */
    public function withdraw(ResignationRequest $resignation, User $by, string $channel = ''): void
    {
        $this->assertPending($resignation);

        $resignation->update(['status' => 'withdrawn', 'decided_at' => now()]);
        $name = $this->name($resignation);

        $this->log($resignation, 'updated', "{$name} withdrew their resignation{$channel}");

        Notifier::toPermission(
            'offboarding.manage',
            "{$name} withdrew their resignation",
            'It is off the list of resignations to review.',
            url: '/offboarding/resignations',
            category: 'offboarding',
            actor: $by,
            except: [$by->id],
        );
    }

    /**
     * Accept a resignation: it becomes the employee's exit, with the last day HR
     * agrees to, and their clearance starts. An exit cancelled before is opened
     * again rather than refused.
     *
     * @throws OffboardingException
     */
    public function accept(ResignationRequest $resignation, User $by, string $lastWorkingDay, ?OffboardingProgram $program = null, ?string $note = null, string $channel = ''): OffboardingCase
    {
        $this->assertPending($resignation);

        $resignation->loadMissing('employee');
        $employee = $resignation->employee;

        if ($employee === null) {
            throw new OffboardingException('That employee is no longer on file.');
        }

        $attributes = [
            'type' => 'resignation',
            'notice_date' => OrganizationClock::localDate(CarbonImmutable::instance($resignation->created_at)),
            'last_working_day' => $lastWorkingDay,
            'reason' => $resignation->reason,
        ];

        $case = DB::transaction(function () use ($resignation, $employee, $attributes, $program, $by, $note, $channel): OffboardingCase {
            $existing = $employee->offboardingCase()->first();

            if ($existing !== null && $existing->isActive()) {
                throw new OffboardingException("{$employee->full_name}’s exit is already under way.");
            }

            // Decided first, so starting the exit — which settles any
            // resignation still waiting ({@see settleFor()}) — leaves this one
            // with HR's own note.
            $resignation->update([
                'status' => 'accepted',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim((string) $note) : null,
            ]);

            if ($existing !== null) {
                // A cancelled (or reopened-then-closed) exit: bring it back with
                // the resignation's details rather than start a second one.
                $this->offboarding->update($existing, $attributes, $channel);
                $this->offboarding->transition($existing, 'reopen', $channel);
                $case = $existing->refresh();

                if (! $case->exitInterview()->exists() && ExitInterviews::shouldSendAutomatically($case)) {
                    app(ExitInterviews::class)->send($case, channel: $channel);
                }
            } else {
                $case = $this->offboarding->start($employee, $attributes, $program, $channel);
            }

            $resignation->update(['offboarding_case_id' => $case->id]);

            return $case;
        });

        $lastDay = CarbonImmutable::parse($lastWorkingDay)->format('M j, Y');

        $this->log($resignation, 'updated', "Accepted {$employee->full_name}’s resignation (last day {$lastDay}){$channel}");

        if ($employee->user !== null) {
            Notifier::toUser(
                $employee->user,
                'Your resignation was accepted',
                "Your last working day is {$lastDay}. Your clearance has started — see what each department still needs from you.",
                url: '/offboarding/me',
                category: 'offboarding',
                actor: $by,
            );
        }

        return $case;
    }

    /**
     * Decline a resignation, saying why.
     *
     * @throws OffboardingException
     */
    public function decline(ResignationRequest $resignation, User $by, string $note, string $channel = ''): void
    {
        $this->assertPending($resignation);

        $resignation->update([
            'status' => 'declined',
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => trim($note),
        ]);

        $resignation->loadMissing('employee.user');
        $name = $this->name($resignation);

        $this->log($resignation, 'updated', "Declined {$name}’s resignation{$channel}");

        if ($resignation->employee?->user !== null) {
            Notifier::toUser(
                $resignation->employee->user,
                'HR declined your resignation',
                trim($note),
                url: '/offboarding/me',
                level: 'warning',
                category: 'offboarding',
                actor: $by,
            );
        }
    }

    /**
     * Mark a waiting resignation accepted when HR starts the exit by hand, so it
     * does not wait for a decision already made.
     */
    public static function settleFor(OffboardingCase $case): void
    {
        ResignationRequest::query()
            ->where('employee_id', $case->employee_id)
            ->pending()
            ->update([
                'status' => 'accepted',
                'decided_at' => now(),
                'decision_note' => 'Accepted when HR started the exit.',
                'offboarding_case_id' => $case->id,
            ]);
    }

    /**
     * @throws OffboardingException
     */
    private function assertPending(ResignationRequest $resignation): void
    {
        if (! $resignation->isPending()) {
            throw new OffboardingException("This resignation was already {$resignation->status}.");
        }
    }

    private function name(ResignationRequest $resignation): string
    {
        $resignation->loadMissing('employee:id,first_name,middle_name,last_name,suffix,user_id');

        return $resignation->employee?->full_name ?? 'An employee';
    }

    private function log(ResignationRequest $resignation, string $event, string $description): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $resignation,
            logName: 'offboarding',
            subjectLabel: $this->name($resignation),
        );
    }
}
