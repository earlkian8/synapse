<?php

namespace App\Support\Reports\Reports;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Support\Reports\Concerns\BuildsReport;
use App\Support\Reports\Report;
use Illuminate\Support\Collection;

/**
 * The recruitment pipeline: every application received within a period, where it sits
 * in the funnel, and when it was decided. The audit trail of the hiring process.
 */
class RecruitmentPipelineReport implements Report
{
    use BuildsReport;

    private const STAGE_LABELS = [
        'applied' => 'Applied',
        'screening' => 'Screening',
        'interview' => 'Interview',
        'offer' => 'Offer',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
    ];

    public function key(): string
    {
        return 'recruitment-pipeline';
    }

    public function name(): string
    {
        return 'Recruitment Pipeline';
    }

    public function description(): string
    {
        return 'Applications received within a period, by posting and pipeline stage.';
    }

    public function group(): string
    {
        return 'Recruitment';
    }

    public function permission(): string
    {
        return 'recruitment.view';
    }

    public function filters(): array
    {
        return [
            $this->dateRangeFilter('Applied between', now()->subDays(90), now()),
            $this->selectFilter('stage', 'Stage', self::STAGE_LABELS, 'All stages'),
            $this->selectFilter('posting', 'Posting', $this->postingOptions(), 'All postings'),
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'applicant', 'label' => 'Applicant', 'align' => 'left', 'type' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'align' => 'left', 'type' => 'text'],
            ['key' => 'posting', 'label' => 'Posting', 'align' => 'left', 'type' => 'text'],
            ['key' => 'stage', 'label' => 'Stage', 'align' => 'left', 'type' => 'badge'],
            ['key' => 'source', 'label' => 'Source', 'align' => 'left', 'type' => 'text'],
            ['key' => 'applied_at', 'label' => 'Applied', 'align' => 'left', 'type' => 'date'],
            ['key' => 'decided_at', 'label' => 'Decided', 'align' => 'left', 'type' => 'date'],
        ];
    }

    public function rows(array $params): Collection
    {
        $query = JobApplication::query()
            ->with(['applicant:id,first_name,last_name,email,source', 'jobPosting:id,title'])
            ->whereNotNull('applied_at')
            ->whereDate('applied_at', '>=', $params['start'])
            ->whereDate('applied_at', '<=', $params['end']);

        if ($params['stage'] !== 'all') {
            $query->where('stage', $params['stage']);
        }

        if ($params['posting'] !== 'all') {
            $query->where('job_posting_id', (int) $params['posting']);
        }

        return $query
            ->orderByDesc('applied_at')
            ->get()
            ->map(fn (JobApplication $application): array => [
                'applicant' => $application->applicant
                    ? trim("{$application->applicant->first_name} {$application->applicant->last_name}")
                    : '—',
                'email' => $application->applicant?->email ?? '—',
                'posting' => $application->jobPosting?->title ?? '—',
                'stage' => self::STAGE_LABELS[$application->stage] ?? $application->stage,
                'source' => $application->applicant?->source ?? '—',
                'applied_at' => $application->applied_at?->toDateString() ?? '',
                'decided_at' => $application->decided_at?->toDateString() ?? '—',
            ])
            ->values();
    }

    public function summary(Collection $rows, array $params): array
    {
        return [
            ['label' => 'Applications', 'value' => number_format($rows->count())],
            ['label' => 'Offers', 'value' => number_format($rows->where('stage', 'Offer')->count())],
            ['label' => 'Hired', 'value' => number_format($rows->where('stage', 'Hired')->count())],
            ['label' => 'Rejected', 'value' => number_format($rows->where('stage', 'Rejected')->count())],
        ];
    }

    public function charts(Collection $rows, array $params): array
    {
        return [
            $this->barsFromCounts('Applications by stage', $rows->groupBy('stage')->map->count()->all()),
            $this->barsFromCounts('By source', $rows->groupBy('source')->map->count()->all()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function postingOptions(): array
    {
        return JobPosting::query()
            ->orderByDesc('created_at')
            ->pluck('title', 'id')
            ->mapWithKeys(fn (string $title, int $id): array => [(string) $id => $title])
            ->all();
    }
}
