<?php

namespace App\Support\Recognition;

use App\Models\Employee;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Spending points on rewards (ADR 0071). Redeeming takes the points and one
 * from stock in the same transaction, with the person and the reward locked,
 * so two taps — or two phones — cannot spend the same points twice or take
 * the last one twice. HR fulfils or declines the request; the person may
 * cancel it while it waits. A decline or a cancel gives the points and the
 * stock back. Nobody handles their own request.
 */
class RewardWorkflow
{
    public function __construct(private readonly PointsLedger $ledger) {}

    /**
     * @throws RecognitionException
     */
    public function redeem(Employee $employee, Reward $reward, ?string $note, string $channel = ''): RewardRedemption
    {
        $redemption = DB::transaction(function () use ($employee, $reward, $note): RewardRedemption {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $reward = Reward::withTrashed()->lockForUpdate()->findOrFail($reward->id);

            if ($reward->trashed() || ! $reward->is_active) {
                throw new RecognitionException("{$reward->name} is no longer offered.");
            }

            if ($reward->stock !== null && $reward->stock < 1) {
                throw new RecognitionException("{$reward->name} is out of stock.");
            }

            $balance = $this->ledger->balance($employee);

            if ($balance < $reward->cost) {
                throw new RecognitionException("You have {$balance} points; {$reward->name} needs {$reward->cost}.");
            }

            $redemption = RewardRedemption::create([
                'reward_id' => $reward->id,
                'employee_id' => $employee->id,
                'cost' => $reward->cost,
                'note' => filled($note) ? trim((string) $note) : null,
            ]);

            $this->ledger->post($employee, -$reward->cost, 'redemption', $redemption, $reward->name, $employee->user);

            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }

            return $redemption;
        });

        Notifier::toPermission(
            permission: 'awards.manage',
            title: "Reward requested: {$reward->name}",
            body: "{$employee->full_name} spent {$reward->cost} points".($redemption->note ? " — “{$redemption->note}”" : '.'),
            url: '/awards/rewards',
            level: 'info',
            category: 'awards',
            actor: $employee->user,
            except: array_values(array_filter([$employee->user_id])),
        );

        ActivityLogger::log(
            event: 'created',
            description: "{$employee->full_name} redeemed {$reward->cost} points for {$reward->name}{$channel}",
            subject: $redemption,
            logName: 'awards',
            subjectLabel: $employee->full_name,
        );

        return $redemption;
    }

    /**
     * The person takes their request back while it waits.
     *
     * @throws RecognitionException
     */
    public function cancel(RewardRedemption $redemption, Employee $employee, string $channel = ''): void
    {
        if ($redemption->employee_id !== $employee->id) {
            throw new RecognitionException('Only who asked for it can cancel it.');
        }

        $this->close($redemption, 'cancelled', null, null, refund: true);

        ActivityLogger::log(
            event: 'updated',
            description: "{$employee->full_name} cancelled a request for ".($redemption->reward?->name ?? 'a reward').$channel,
            subject: $redemption,
            logName: 'awards',
            subjectLabel: $employee->full_name,
        );
    }

    /**
     * HR hands it over.
     *
     * @throws RecognitionException
     */
    public function fulfil(RewardRedemption $redemption, User $by, ?string $note, string $channel = ''): void
    {
        $this->assertNotOwn($redemption, $by);
        $this->close($redemption, 'fulfilled', $by, $note, refund: false);

        $this->tell($redemption, $by, "Your reward is ready: {$redemption->reward?->name}", $note ?? 'HR has it for you.', 'success');
        $this->log($redemption, "Fulfilled {$redemption->employee?->full_name}'s request for {$redemption->reward?->name}{$channel}");
    }

    /**
     * HR turns it down; the points and the stock go back.
     *
     * @throws RecognitionException
     */
    public function decline(RewardRedemption $redemption, User $by, ?string $note, string $channel = ''): void
    {
        $this->assertNotOwn($redemption, $by);
        $this->close($redemption, 'declined', $by, $note, refund: true);

        $this->tell($redemption, $by, "Your request for {$redemption->reward?->name} was declined", trim(($note ?? '').' Your '.$redemption->cost.' points are back.'), 'warning');
        $this->log($redemption, "Declined {$redemption->employee?->full_name}'s request for {$redemption->reward?->name}{$channel}");
    }

    /**
     * Settle a pending request; a refund gives the points and the stock back.
     *
     * @throws RecognitionException
     */
    private function close(RewardRedemption $redemption, string $status, ?User $by, ?string $note, bool $refund): void
    {
        DB::transaction(function () use ($redemption, $status, $by, $note, $refund): void {
            $fresh = RewardRedemption::query()->lockForUpdate()->findOrFail($redemption->id);

            if ($fresh->status !== 'pending') {
                throw new RecognitionException("This request was already {$fresh->status}.");
            }

            $redemption->update([
                'status' => $status,
                'handled_by' => $by?->id,
                'handled_at' => now(),
                'response_note' => filled($note) ? trim((string) $note) : null,
            ]);

            if ($refund) {
                if ($redemption->employee) {
                    $this->ledger->post($redemption->employee, $redemption->cost, 'refund', $redemption, $redemption->reward?->name, $by);
                }

                if ($redemption->reward?->stock !== null) {
                    $redemption->reward->increment('stock');
                }
            }
        });
    }

    /**
     * @throws RecognitionException
     */
    private function assertNotOwn(RewardRedemption $redemption, User $by): void
    {
        if ($redemption->employee?->user_id === $by->id) {
            throw new RecognitionException('You can’t handle your own request — ask another HR manager.');
        }
    }

    private function tell(RewardRedemption $redemption, User $by, string $title, string $body, string $level): void
    {
        $user = $redemption->employee?->user;

        if ($user && $user->is_active) {
            Notifier::toUser(
                user: $user,
                title: $title,
                body: $body,
                url: '/awards/points',
                level: $level,
                category: 'awards',
                actor: $by,
            );
        }
    }

    private function log(RewardRedemption $redemption, string $description): void
    {
        ActivityLogger::log(
            event: 'updated',
            description: $description,
            subject: $redemption,
            logName: 'awards',
            subjectLabel: $redemption->employee?->full_name,
        );
    }
}
