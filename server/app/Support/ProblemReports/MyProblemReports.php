<?php

namespace App\Support\ProblemReports;

use App\Models\ProblemReport;
use App\Models\ProblemReportAttachment;
use App\Models\User;
use App\Support\Files\Uploads;

/**
 * One's own problem reports (ADR 0096), as the reporter reads them — on the
 * web's Your reports and in the app alike, so the two cannot disagree.
 *
 * The reporter sees what they sent, where it stands, and the SYNAPSE team's
 * reply. Never the admins' internal note, nor which admin handled it.
 */
final class MyProblemReports
{
    /** The most a reporter's list holds; older reports drop off the end. */
    public const LIMIT = 100;

    /**
     * @return list<array<string, mixed>>
     */
    public static function for(User $user): array
    {
        return ProblemReport::query()
            ->where('user_id', $user->id)
            ->with('attachments')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ProblemReport $report): array => self::present($report))
            ->all();
    }

    public static function find(User $user, string $reference): ?ProblemReport
    {
        $reference = ProblemReports::normalizeReference($reference);

        if ($reference === null) {
            return null;
        }

        return ProblemReport::query()
            ->where('user_id', $user->id)
            ->where('reference', $reference)
            ->with('attachments')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ProblemReport $report): array
    {
        return [
            'reference' => $report->reference,
            'category' => $report->category,
            'category_label' => $report->categoryLabel(),
            'impact' => $report->impact,
            'impact_label' => $report->impactLabel(),
            'subject' => $report->subject,
            'description' => $report->description,
            'platform' => $report->platform,
            'platform_label' => $report->platformLabel(),
            'page' => $report->page,
            'app_version' => $report->app_version,
            'status' => $report->status,
            'status_label' => $report->statusLabel('reporter'),
            'is_open' => $report->isOpen(),
            'reply' => $report->reply,
            'replied_at' => $report->replied_at?->toIso8601String(),
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'updated_at' => ($report->status_changed_at ?? $report->updated_at)?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
            'attachments' => $report->attachments
                ->map(fn (ProblemReportAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->name,
                    'url' => Uploads::url($attachment->path),
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                ])
                ->values()
                ->all(),
        ];
    }
}
