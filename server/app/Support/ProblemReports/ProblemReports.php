<?php

namespace App\Support\ProblemReports;

use App\Models\ProblemReport;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Filing a problem report (ADR 0096) — the one way in, for the web's dialog and
 * the app's form alike.
 *
 * The report is the reporter's, written to the platform: it is stamped with
 * the workspace they were in for context, but nobody in that workspace reads
 * it. The workspace's activity log records only that a report was sent and its
 * reference, never what it says.
 */
final class ProblemReports
{
    /** Readable on a phone screen and over the phone: no 0/O, 1/I/L. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly Screenshots $screenshots,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @param  array{category: string, impact: string, subject: string, description: string, platform: string, page?: ?string, app_version?: ?string, device?: ?string, context?: ?array<string, mixed>}  $input
     * @param  list<UploadedFile>  $screenshots
     */
    public function file(User $reporter, array $input, array $screenshots = []): ProblemReport
    {
        $stored = [];

        try {
            foreach ($screenshots as $file) {
                $stored[] = $this->screenshots->store($file);
            }

            $report = DB::transaction(function () use ($reporter, $input, $stored): ProblemReport {
                $report = ProblemReport::create([
                    'reference' => $this->reference(),
                    'user_id' => $reporter->id,
                    'organization_id' => $this->tenancy->id(),
                    'category' => $input['category'],
                    'impact' => $input['impact'],
                    'subject' => trim($input['subject']),
                    'description' => trim($input['description']),
                    'platform' => $input['platform'],
                    'page' => $input['page'] ?? null,
                    'app_version' => $input['app_version'] ?? null,
                    'device' => $input['device'] ?? null,
                    'context' => array_filter($input['context'] ?? [], fn ($value) => $value !== null && $value !== '') ?: null,
                    'status' => 'new',
                    'status_changed_at' => now(),
                ]);

                $report->attachments()->createMany($stored);

                return $report;
            });
        } catch (Throwable $e) {
            $this->screenshots->discard(array_column($stored, 'path'));

            throw $e;
        }

        if ($this->tenancy->check()) {
            ActivityLogger::log(
                'reported',
                "Sent problem report {$report->reference} to the SYNAPSE team",
                properties: ['platform' => $report->platform],
                logName: 'support',
                subjectLabel: $report->reference,
            );
        }

        return $report->load('attachments');
    }

    /**
     * A reference nobody has: PR- and six characters, about 900 million of them.
     */
    private function reference(): string
    {
        do {
            $code = 'PR-';

            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (ProblemReport::query()->where('reference', $code)->exists());

        return $code;
    }

    /**
     * The reference as somebody typed it into an address: case and spacing
     * forgiven, anything else refused.
     */
    public static function normalizeReference(string $reference): ?string
    {
        $reference = Str::upper(trim($reference));

        return preg_match('/^PR-['.self::ALPHABET.']{6}$/', $reference) === 1 ? $reference : null;
    }
}
