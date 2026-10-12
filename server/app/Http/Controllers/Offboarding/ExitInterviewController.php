<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exit interviews for HR (ADR 0076): what leavers said, read together — why
 * people leave and how they rate their time here — over a period, with every
 * survey of the period below; and, on an exit, sending one by hand or waiving
 * one that will not come back. Answers are `offboarding.view`'s to read.
 */
class ExitInterviewController extends Controller
{
    use OffboardingResponses;

    /** The periods the insights read, by key: months back, or all. */
    private const PERIODS = ['12m' => 12, '6m' => 6, '3m' => 3, 'all' => null];

    public function __construct(private readonly ExitInterviews $interviews) {}

    public function index(Request $request): Response
    {
        $period = array_key_exists($request->string('period')->toString(), self::PERIODS)
            ? $request->string('period')->toString()
            : '12m';
        $months = self::PERIODS[$period];

        $interviews = ExitInterview::query()
            ->whereHas('offboardingCase')
            ->whereHas('employee')
            ->when($months !== null, fn ($query) => $query->where('sent_at', '>=', now()->subMonths($months)))
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id',
                'employee.department:id,name',
                'offboardingCase:id,type,status,last_working_day',
            ])
            ->latest('sent_at')
            ->get();

        $submitted = $interviews->where('status', 'submitted');
        $asked = $interviews->where('status', '!=', 'waived')->count();

        return Inertia::render('offboarding/interviews', [
            'period' => $period,
            'summary' => [
                ...ExitInterviewQuestionnaire::summarize($submitted->values()),
                'sent' => $interviews->count(),
                'waiting' => $interviews->where('status', 'pending')->count(),
                'waived' => $interviews->where('status', 'waived')->count(),
                'response_rate' => $asked > 0 ? round($submitted->count() / $asked, 3) : null,
            ],
            'interviews' => $interviews->map(fn (ExitInterview $interview): array => [
                'id' => $interview->id,
                'status' => $interview->status,
                'case_id' => $interview->offboardingCase->hashid,
                'exit_type' => $interview->offboardingCase->type,
                'last_working_day' => $interview->offboardingCase->last_working_day?->toDateString(),
                'employee' => [
                    'full_name' => $interview->employee->full_name,
                    'initials' => $interview->employee->initials(),
                    'employee_no' => $interview->employee->employee_no,
                    'photo' => $interview->employee->photo_url,
                    'department' => $interview->employee->department?->name,
                ],
                'sent_on' => $interview->sent_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($interview->sent_at)) : null,
                'submitted_on' => $interview->submitted_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($interview->submitted_at)) : null,
                'reason' => ExitInterviewQuestionnaire::REASONS[$interview->answers['reason'] ?? ''] ?? null,
                'average' => ExitInterviewQuestionnaire::averageRating($interview->answers),
                'recommend' => $interview->answers['recommend'] ?? null,
            ])->values()->all(),
            'nav' => $this->navCounts($request),
        ]);
    }

    /**
     * Send the survey for an exit that has none — a termination, or one started
     * before surveys were sent.
     */
    public function send(Request $request, OffboardingCase $case): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->interviews->send($case, $request->user()),
            'Exit interview sent.',
        );
    }

    public function waive(Request $request, OffboardingCase $case): RedirectResponse
    {
        $interview = $case->exitInterview()->first();

        abort_if($interview === null, 404);

        return $this->attempt(
            fn () => $this->interviews->waive($interview, $request->user()),
            'Exit interview waived.',
        );
    }
}
