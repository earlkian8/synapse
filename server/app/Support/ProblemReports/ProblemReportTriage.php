<?php

namespace App\Support\ProblemReports;

use App\Models\Admin;
use App\Models\ProblemReport;
use App\Support\Admin\AdminAudit;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A platform admin following a problem report up (ADR 0096): where it stands,
 * the reply its reporter reads, and a note only admins read.
 *
 * Every change is recorded in the admin activity log. The reporter is told
 * when the report moves on (looked into, resolved, closed) or the reply
 * changes — in the ERP's bell, and by email and push as they have chosen. A
 * note between admins, or putting a report back to New, tells them nothing.
 */
final class ProblemReportTriage
{
    /**
     * @param  array{status: string, reply?: ?string, internal_note?: ?string}  $input
     */
    public function update(ProblemReport $report, array $input, Admin $admin): ProblemReport
    {
        [$report, $changes] = DB::transaction(function () use ($report, $input, $admin): array {
            /** @var ProblemReport $report */
            $report = ProblemReport::query()->lockForUpdate()->findOrFail($report->id);

            $from = $report->status;
            $status = $input['status'];
            $reply = self::clean($input['reply'] ?? null);
            $note = self::clean($input['internal_note'] ?? null);

            $changes = [
                'status' => $status !== $from ? ['from' => $from, 'to' => $status] : null,
                'reply' => $reply !== $report->reply,
                'note' => $note !== $report->internal_note,
            ];

            if ($changes['status'] === null && ! $changes['reply'] && ! $changes['note']) {
                return [$report, null];
            }

            if ($changes['status'] !== null) {
                $report->status = $status;
                $report->status_changed_at = now();
                $report->resolved_at = $status === 'resolved' ? now() : null;
            }

            if ($changes['reply']) {
                $report->reply = $reply;
                $report->replied_at = $reply !== null ? now() : null;
            }

            $report->internal_note = $note;
            $report->handled_by = $admin->id;
            $report->save();

            return [$report, $changes];
        });

        if ($changes === null) {
            return $report;
        }

        AdminAudit::record(
            'problem_report_updated',
            $this->describe($report, $changes),
            $admin,
            $report,
            array_filter([
                'status' => $changes['status'],
                'replied' => $changes['reply'] && $report->reply !== null ? true : null,
                'note_changed' => $changes['note'] ?: null,
            ]),
            $report->reference,
        );

        $this->tellReporter($report, $changes);

        return $report;
    }

    /**
     * @param  array{status: ?array{from: string, to: string}, reply: bool, note: bool}  $changes
     */
    private function describe(ProblemReport $report, array $changes): string
    {
        $parts = [];

        if ($changes['status'] !== null) {
            $parts[] = 'marked it '.ProblemReport::STATUSES[$changes['status']['to']]['admin'];
        }

        if ($changes['reply']) {
            $parts[] = $report->reply !== null ? 'replied to the reporter' : 'withdrew the reply';
        }

        if ($changes['note']) {
            $parts[] = 'updated the internal note';
        }

        return "Problem report {$report->reference}: ".implode(', ', $parts).'.';
    }

    /**
     * @param  array{status: ?array{from: string, to: string}, reply: bool, note: bool}  $changes
     */
    private function tellReporter(ProblemReport $report, array $changes): void
    {
        $moved = $changes['status'] !== null && $changes['status']['to'] !== 'new';
        $replied = $changes['reply'] && $report->reply !== null;

        if (! $moved && ! $replied) {
            return;
        }

        $reporter = $report->user;

        if ($reporter === null || $reporter->trashed() || ! $reporter->is_active) {
            return;
        }

        $subject = '“'.Str::limit($report->subject, 80).'”';
        $title = match (true) {
            $moved && $report->status === 'investigating' => "We’re looking into {$report->reference}",
            $moved && $report->status === 'resolved' => "{$report->reference} is resolved",
            $moved && $report->status === 'closed' => "{$report->reference} is closed",
            default => "The SYNAPSE team replied about {$report->reference}",
        };
        $body = $replied
            ? Str::limit($report->reply, 240)
            : match ($report->status) {
                'investigating' => "The SYNAPSE team is looking into {$subject}.",
                'resolved' => "The SYNAPSE team has resolved {$subject}. Thanks for reporting it.",
                default => "The SYNAPSE team has closed {$subject}.",
            };

        Notifier::toUser(
            $reporter,
            $title,
            $body,
            url: '/help/reports?report='.$report->reference,
            level: $report->status === 'resolved' ? 'success' : 'info',
            category: 'support',
        );
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
