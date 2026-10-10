<?php

namespace App\Support\Recognition;

use App\Models\Employee;
use App\Models\Kudos;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Peer-to-peer kudos (ADR 0071): a short thank-you from one employee to
 * another, on the recognition wall. It carries the organisation's
 * `kudos_points` while the sender has kudos with points left this month
 * ({@see PointsLedger::kudosLeftThisMonth()}); past that it still goes out,
 * with none. The recipient is told.
 *
 * The web, the mobile app and the assistant all come through here.
 */
class KudosWorkflow
{
    public const MAX_LENGTH = 500;

    public function __construct(private readonly PointsLedger $ledger) {}

    /**
     * @throws RecognitionException
     */
    public function send(Employee $from, Employee $to, string $message, string $channel = ''): Kudos
    {
        $message = trim($message);

        if ($from->is($to)) {
            throw new RecognitionException('Kudos are for someone else.', 'to_employee_id');
        }

        if ($to->employment_status !== 'active') {
            throw new RecognitionException('Kudos go to active colleagues.', 'to_employee_id');
        }

        if ($message === '') {
            throw new RecognitionException('Say what they did — a line is enough.', 'message');
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            throw new RecognitionException('Keep it to 500 characters.', 'message');
        }

        $kudos = DB::transaction(function () use ($from, $to, $message): Kudos {
            // One sender's kudos are counted one at a time, so two at once
            // cannot both take the last point-carrying slot.
            Employee::query()->whereKey($from->id)->lockForUpdate()->first();

            $points = $this->ledger->kudosLeftThisMonth($from) > 0
                ? (int) (app(Tenancy::class)->organization()?->kudos_points ?? 0)
                : 0;

            $kudos = Kudos::create([
                'from_employee_id' => $from->id,
                'to_employee_id' => $to->id,
                'message' => $message,
                'points' => $points,
            ]);

            $this->ledger->post($to, $points, 'kudos', $kudos, "Kudos from {$from->full_name}", $from->user);

            return $kudos;
        });

        $recipient = $to->user;

        if ($recipient && $recipient->is_active) {
            Notifier::toUser(
                user: $recipient,
                title: Str::before($from->full_name, ' ').' sent you kudos'.($kudos->points > 0 ? " (+{$kudos->points} points)" : ''),
                body: Str::limit($message, 140),
                url: '/awards/wall',
                level: 'success',
                category: 'awards',
                actor: $from->user,
            );
        }

        ActivityLogger::log(
            event: 'created',
            description: "{$from->full_name} sent kudos to {$to->full_name}{$channel}",
            subject: $kudos,
            logName: 'awards',
            subjectLabel: $to->full_name,
        );

        return $kudos;
    }

    /**
     * Take kudos down from the wall (HR); its points are reversed.
     */
    public function remove(Kudos $kudos, User $by, string $channel = ''): void
    {
        DB::transaction(function () use ($kudos, $by): void {
            if ($kudos->recipient) {
                $this->ledger->settle($kudos->recipient, $kudos, 0, 'kudos', 'Kudos taken down', $by);
            }

            $kudos->delete();
        });

        ActivityLogger::log(
            event: 'deleted',
            description: 'Took down kudos to '.($kudos->recipient?->full_name ?? 'a former employee').$channel,
            logName: 'awards',
            subjectLabel: $kudos->recipient?->full_name,
        );
    }
}
