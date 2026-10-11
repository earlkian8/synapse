<?php

namespace App\Support\Offboarding;

use App\Models\ClearanceItem;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * The exit-interview survey's life (ADR 0076): sent to the leaver when an exit
 * starts, answered by them, reminded about once, or waived by HR.
 *
 * - **Sent automatically** with every exit but a termination — asking somebody
 *   who was let go how they rate the company is HR's call, so HR sends one by
 *   hand when it wants one ({@see shouldSendAutomatically()}).
 * - **Answering it signs off the checklist's exit-interview item**, when there is
 *   one still pending: the clearance said "Conduct exit interview", and it has.
 * - **The answers are HR's to read** (`offboarding.view`). The audit trail says
 *   that a survey was sent or answered, never what it said.
 */
class ExitInterviews
{
    /** Days a survey waits before its one reminder. */
    public const REMIND_AFTER_DAYS = 3;

    public function __construct(private readonly OffboardingWorkflow $workflow) {}

    /**
     * Whether starting this exit sends the survey by itself.
     */
    public static function shouldSendAutomatically(OffboardingCase $case): bool
    {
        return $case->type !== 'termination';
    }

    /**
     * Send the survey to the leaver, and tell them so when they have an account.
     *
     * @throws OffboardingException
     */
    public function send(OffboardingCase $case, ?User $by = null, string $channel = ''): ExitInterview
    {
        if ($case->exitInterview()->exists()) {
            throw new OffboardingException('An exit interview was already sent for this exit.');
        }

        if ($case->status === 'cancelled') {
            throw new OffboardingException('This exit was cancelled, so there is nobody leaving to ask.');
        }

        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix,user_id', 'employee.user');

        $interview = $case->exitInterview()->create([
            'employee_id' => $case->employee_id,
            'status' => 'pending',
            'questions' => ExitInterviewQuestionnaire::questions(),
            'sent_at' => now(),
        ]);

        $name = $case->employee?->full_name ?? 'an employee';

        ActivityLogger::log(
            event: 'created',
            description: "Sent the exit interview to {$name}".($by === null ? ' (with the exit)' : '').$channel,
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $name,
        );

        if ($case->employee?->user !== null) {
            Notifier::toUser(
                $case->employee->user,
                'Tell us about your time here',
                'Before you go, please answer a short exit interview. It takes about five minutes, and only the people who handle exits read it.',
                url: '/offboarding/me/exit-interview',
                category: 'offboarding',
                actor: $by,
            );
        }

        return $interview;
    }

    /**
     * Record the leaver's answers, sign off the checklist's exit-interview item
     * and tell HR it is in.
     *
     * @param  array<string, mixed>  $answers  Already validated against the survey's questions.
     *
     * @throws OffboardingException
     */
    public function submit(ExitInterview $interview, array $answers, User $by): void
    {
        $interview->loadMissing('offboardingCase', 'employee:id,first_name,middle_name,last_name,suffix');

        if (! $interview->isPending()) {
            throw new OffboardingException($interview->status === 'submitted'
                ? 'You have already answered this exit interview.'
                : 'HR no longer needs this exit interview.');
        }

        if ($interview->offboardingCase?->status === 'cancelled') {
            throw new OffboardingException('Your exit was cancelled, so there is no exit interview to answer.');
        }

        $name = $interview->employee?->full_name ?? 'An employee';

        DB::transaction(function () use ($interview, $answers, $by): void {
            $interview->update([
                'status' => 'submitted',
                'answers' => ExitInterviewQuestionnaire::clean($interview->questions ?? [], $answers),
                'submitted_at' => now(),
            ]);

            $case = $interview->offboardingCase;

            if ($case === null) {
                return;
            }

            $case->clearanceItems()
                ->where('status', 'pending')
                ->get()
                ->filter(fn (ClearanceItem $item): bool => str_contains(mb_strtolower($item->item), 'exit interview'))
                ->each(fn (ClearanceItem $item) => $this->workflow->setItemStatus(
                    $item,
                    'cleared',
                    $by,
                    'Answered the exit interview survey.',
                    setRemarks: true,
                    channel: ' (the survey was answered)',
                ));
        });

        ActivityLogger::log(
            event: 'updated',
            description: "{$name} answered their exit interview",
            subject: $interview->offboardingCase,
            logName: 'offboarding',
            subjectLabel: $name,
        );

        if ($interview->offboardingCase !== null) {
            Notifier::toPermission(
                'offboarding.manage',
                'Exit interview answered',
                "{$name} answered their exit interview.",
                url: "/offboarding/{$interview->offboardingCase->hashid}?tab=interview",
                category: 'offboarding',
                actor: $by,
                except: [$by->id],
            );
        }
    }

    /**
     * Stop waiting for a survey: it leaves the leaver's list and is never
     * reminded about.
     *
     * @throws OffboardingException
     */
    public function waive(ExitInterview $interview, User $by, string $channel = ''): void
    {
        if (! $interview->isPending()) {
            throw new OffboardingException('Only an exit interview still waiting for answers can be waived.');
        }

        $interview->update(['status' => 'waived', 'waived_by' => $by->id, 'waived_at' => now()]);
        $interview->loadMissing('employee:id,first_name,middle_name,last_name,suffix', 'offboardingCase');
        $name = $interview->employee?->full_name ?? 'an employee';

        ActivityLogger::log(
            event: 'updated',
            description: "Waived the exit interview for {$name}{$channel}",
            subject: $interview->offboardingCase,
            logName: 'offboarding',
            subjectLabel: $name,
        );
    }

    /**
     * Remind, once, everybody whose survey has waited {@see REMIND_AFTER_DAYS}
     * days while their exit is in progress. For the bound organisation; returns
     * how many were reminded.
     */
    public function sendDueReminders(): int
    {
        $due = ExitInterview::query()
            ->where('status', 'pending')
            ->whereNull('reminded_at')
            ->where('sent_at', '<=', now()->subDays(self::REMIND_AFTER_DAYS))
            ->whereHas('offboardingCase', fn ($query) => $query->whereIn('status', ['initiated', 'clearance']))
            ->with('employee.user')
            ->get();

        $reminded = 0;

        foreach ($due as $interview) {
            $interview->update(['reminded_at' => now()]);

            if ($interview->employee?->user === null) {
                continue;
            }

            $reminded += Notifier::toUser(
                $interview->employee->user,
                'Your exit interview is waiting',
                'A few minutes before you go: tell us what worked and what we should change. Only the people who handle exits read it.',
                url: '/offboarding/me/exit-interview',
                category: 'offboarding',
            ) > 0 ? 1 : 0;
        }

        return $reminded;
    }
}
