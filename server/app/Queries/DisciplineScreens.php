<?php

namespace App\Queries;

use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Discipline\ConductRecord;
use App\Support\Discipline\DisciplineWorkflow;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * What each screen of Discipline (ADR 0105) is shown, and to whom:
 *
 *  - HR (`discipline.view`): every case, whole — evidence and witnesses too;
 *  - a reporter (`discipline.report`): the reports they filed, and how far each
 *    got, never the explanation or the reasons;
 *  - the employee (`discipline.respond`): the cases served on them — the
 *    charge, the facts, the dates and the decision; never the report before it
 *    was served, the witnesses or the evidence, which stay with HR.
 */
class DisciplineScreens
{
    /** The groups the cases list is split into. */
    public const VIEWS = [
        'open' => DisciplinaryCase::OPEN,
        'decided' => ['decided'],
        'closed' => ['closed', 'withdrawn'],
    ];

    public function __construct(private readonly DisciplineWorkflow $workflow) {}

    /**
     * @return array<string, mixed>
     */
    public function cases(Request $request): array
    {
        $view = array_key_exists($request->string('view')->toString(), self::VIEWS) ? $request->string('view')->toString() : 'open';
        $search = $request->string('search')->toString();
        $today = OrganizationClock::today();

        $cases = DisciplinaryCase::query()
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id', 'employee.department:id,name', 'offense:id,code,title,gravity'])
            ->whereIn('status', self::VIEWS[$view])
            ->search($search)
            ->latest('incident_date')
            ->latest('id')
            ->paginate(in_array($request->integer('per_page'), [10, 15, 25, 50, 100], true) ? $request->integer('per_page') : 25)
            ->withQueryString();

        $open = DisciplinaryCase::query()->open();

        return [
            'cases' => [
                'data' => collect($cases->items())->map(fn (DisciplinaryCase $case): array => $this->summary($case, $today))->all(),
                'meta' => [
                    'current_page' => $cases->currentPage(),
                    'last_page' => $cases->lastPage(),
                    'per_page' => $cases->perPage(),
                    'total' => $cases->total(),
                    'from' => $cases->firstItem(),
                    'to' => $cases->lastItem(),
                ],
            ],
            'filters' => ['view' => $view, 'search' => $search],
            'counts' => collect(self::VIEWS)->map(fn (array $statuses): int => DisciplinaryCase::query()->whereIn('status', $statuses)->count()),
            'stats' => [
                'reported' => (clone $open)->where('status', 'reported')->count(),
                'awaiting' => (clone $open)->whereNotNull('notice_issued_at')->whereNull('explained_at')->whereDate('reply_due_on', '>=', $today)->count(),
                'overdue' => (clone $open)->whereNotNull('notice_issued_at')->whereNull('explained_at')->whereDate('reply_due_on', '<', $today)->count(),
                'hearings' => (clone $open)->where('status', 'hearing')->whereNull('hearing_held_at')->count(),
            ],
            'options' => $this->reportOptions(),
            'can' => [
                'manage' => $request->user()->can('discipline.manage'),
                'report' => $request->user()->can('discipline.report'),
            ],
        ];
    }

    /**
     * One case, whole, for HR.
     *
     * @return array<string, mixed>
     */
    public function case(DisciplinaryCase $case, Request $request): array
    {
        $case->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id,user_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'offense',
            'attachments.uploader:id,first_name,middle_name,last_name,suffix',
            'reporter:id,first_name,middle_name,last_name,suffix',
            'noticeIssuer:id,first_name,middle_name,last_name,suffix',
            'decider:id,first_name,middle_name,last_name,suffix',
        ]);

        $today = OrganizationClock::today();

        return [
            'case' => [
                ...$this->detail($case, $today),
                'witnesses' => $case->witnesses,
                'reported_by' => $case->reporter?->full_name,
                'notice_issued_by' => $case->noticeIssuer?->full_name,
                'decided_by' => $case->decider?->full_name,
                'suggested_penalty' => $case->suggested_penalty,
                'attachments' => $case->attachments->map(fn (DisciplinaryCaseAttachment $file): array => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'size' => $file->size,
                    'url' => $file->url(),
                    'uploaded_by' => $file->uploader?->full_name,
                    'uploaded_at' => $file->created_at?->toIso8601String(),
                ])->values(),
            ],
            'record' => $case->employee !== null ? ConductRecord::for($case->employee, $today) : null,
            'suggestion' => $this->workflow->suggestion($case),
            'blockers' => $this->workflow->blockers($case),
            'options' => [
                'offenses' => $this->offenses(),
                'penalties' => DisciplinaryCase::PENALTIES,
                'min_reply_days' => DisciplinaryCase::MIN_REPLY_DAYS,
                'earliest_reply' => CarbonImmutable::parse($today)->addDays(DisciplinaryCase::MIN_REPLY_DAYS)->toDateString(),
                'max_preventive_days' => DisciplinaryCase::MAX_PREVENTIVE_DAYS,
                'max_suspension_days' => DisciplinaryCase::MAX_SUSPENSION_DAYS,
            ],
            'today' => $today,
            'can' => [
                'manage' => $request->user()->can('discipline.manage'),
                // Nobody serves or decides a case about themselves.
                'own' => $case->employee?->user_id !== null && $case->employee->user_id === $request->user()->id,
                'offboard' => $request->user()->can('offboarding.manage'),
            ],
        ];
    }

    /**
     * An employee's conduct record, for HR.
     *
     * @return array<string, mixed>
     */
    public function record(Employee $employee): array
    {
        $employee->loadMissing(['department:id,name', 'position:id,title']);
        $today = OrganizationClock::today();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'initials' => $employee->initials(),
                'photo' => $employee->photo_url,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
            ],
            'record' => ConductRecord::for($employee, $today),
            'cases' => DisciplinaryCase::query()
                ->with('offense:id,code,title,gravity')
                ->where('employee_id', $employee->id)
                ->latest('incident_date')
                ->latest('id')
                ->get()
                ->map(fn (DisciplinaryCase $case): array => $this->summary($case, $today))
                ->values(),
        ];
    }

    /**
     * The reports somebody filed, and how far each got — no more.
     *
     * @return list<array<string, mixed>>
     */
    public function reported(User $user): array
    {
        return DisciplinaryCase::query()
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no,photo', 'offense:id,code,title'])
            ->where('reported_by', $user->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (DisciplinaryCase $case): array => [
                'reference' => $case->reference,
                'employee' => $case->employee?->full_name,
                'offense' => $case->offense?->title,
                'incident_date' => $case->incident_date->toDateString(),
                'status' => $case->status,
                'reported_at' => $case->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The cases served on an employee.
     *
     * @return array<string, mixed>
     */
    public function mine(Employee $employee): array
    {
        $today = OrganizationClock::today();

        return [
            'record' => ConductRecord::for($employee, $today),
            'cases' => DisciplinaryCase::query()
                ->with('offense:id,code,title,gravity')
                ->where('employee_id', $employee->id)
                ->served()
                ->latest('notice_issued_at')
                ->get()
                ->map(fn (DisciplinaryCase $case): array => $this->summary($case, $today))
                ->values(),
        ];
    }

    /**
     * One case served on the employee — what they need to answer it.
     *
     * @return array<string, mixed>
     */
    public function mineOne(DisciplinaryCase $case): array
    {
        $case->loadMissing(['offense', 'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo']);

        return ['case' => $this->detail($case, OrganizationClock::today())];
    }

    /**
     * The code of conduct.
     *
     * @return list<array<string, mixed>>
     */
    public function offenses(bool $inactive = false): array
    {
        return ConductOffense::query()
            ->when(! $inactive, fn ($query) => $query->where('is_active', true))
            ->withCount(['cases as upheld_count' => fn ($query) => $query->where('finding', 'upheld')])
            ->orderBy('code')
            ->get()
            ->map(fn (ConductOffense $offense): array => [
                'id' => $offense->id,
                'code' => $offense->code,
                'title' => $offense->title,
                'description' => $offense->description,
                'gravity' => $offense->gravity,
                'ground' => $offense->ground,
                'ground_label' => ConductOffense::GROUNDS[$offense->ground] ?? $offense->ground,
                'just_cause' => $offense->isJustCause(),
                'penalties' => array_values((array) $offense->penalties),
                'reckoning_months' => $offense->reckoning_months,
                'is_active' => $offense->is_active,
                'upheld_count' => (int) $offense->upheld_count,
            ])
            ->values()
            ->all();
    }

    /**
     * What the "Report an incident" dialog chooses from.
     *
     * @return array<string, mixed>
     */
    public function reportOptions(): array
    {
        return [
            'employees' => Employee::query()
                ->whereNotIn('employment_status', ['resigned', 'terminated'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
                ->map(fn (Employee $employee): array => ['id' => $employee->id, 'name' => $employee->full_name, 'employee_no' => $employee->employee_no])
                ->values(),
            'offenses' => collect($this->offenses())->map(fn (array $offense): array => [
                'id' => $offense['id'],
                'code' => $offense['code'],
                'title' => $offense['title'],
            ])->values(),
            'today' => OrganizationClock::today(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DisciplinaryCase $case, string $today): array
    {
        return [
            'hashid' => $case->hashid,
            'reference' => $case->reference,
            'status' => $case->status,
            'employee' => $case->relationLoaded('employee') && $case->employee !== null ? [
                'id' => $case->employee->id,
                'name' => $case->employee->full_name,
                'initials' => $case->employee->initials(),
                'photo' => $case->employee->photo_url,
                'employee_no' => $case->employee->employee_no,
                'department' => $case->employee->relationLoaded('department') ? $case->employee->department?->name : null,
            ] : null,
            'offense' => $case->offense !== null ? ['code' => $case->offense->code, 'title' => $case->offense->title, 'gravity' => $case->offense->gravity] : null,
            'incident_date' => $case->incident_date->toDateString(),
            'reply_due_on' => $case->reply_due_on?->toDateString(),
            'reply_overdue' => $case->isOpen() && $case->replyOverdue($today),
            'explained' => $case->explained_at !== null,
            'hearing_at' => $case->hearing_at?->toIso8601String(),
            'finding' => $case->finding,
            'penalty' => $case->penalty,
            'decided_at' => $case->decided_at?->toIso8601String(),
        ];
    }

    /**
     * What both HR and the employee read of a case.
     *
     * @return array<string, mixed>
     */
    private function detail(DisciplinaryCase $case, string $today): array
    {
        return [
            ...$this->summary($case, $today),
            'employee' => $case->employee !== null ? [
                'id' => $case->employee->id,
                'name' => $case->employee->full_name,
                'initials' => $case->employee->initials(),
                'photo' => $case->employee->photo_url,
                'employee_no' => $case->employee->employee_no,
                'department' => $case->employee->relationLoaded('department') ? $case->employee->department?->name : null,
                'position' => $case->employee->relationLoaded('position') ? $case->employee->position?->title : null,
            ] : null,
            'offense' => $case->offense !== null ? [
                'id' => $case->offense->id,
                'code' => $case->offense->code,
                'title' => $case->offense->title,
                'gravity' => $case->offense->gravity,
                'ground' => ConductOffense::GROUNDS[$case->offense->ground] ?? null,
                'just_cause' => $case->offense->isJustCause(),
            ] : null,
            'incident_time' => $case->incident_time,
            'location' => $case->location,
            'narrative' => $case->narrative,
            'notice_grounds' => $case->notice_grounds,
            'notice_issued_at' => $case->notice_issued_at?->toIso8601String(),
            'notice_received_at' => $case->notice_received_at?->toIso8601String(),
            'preventive_from' => $case->preventive_from?->toDateString(),
            'preventive_to' => $case->preventive_to?->toDateString(),
            'explanation' => $case->explanation,
            'explained_at' => $case->explained_at?->toIso8601String(),
            'explanation_by_hr' => $case->explanation_recorded_by !== null,
            'hearing_requested' => $case->hearing_requested,
            'hearing_place' => $case->hearing_place,
            'hearing_held_at' => $case->hearing_held_at?->toIso8601String(),
            'hearing_attended' => $case->hearing_attended,
            'hearing_notes' => $case->hearing_notes,
            'occurrence' => $case->occurrence,
            'penalty_label' => $case->penalty !== null ? DisciplinaryCase::PENALTIES[$case->penalty] : null,
            'suspension_days' => $case->suspension_days,
            'penalty_from' => $case->penalty_from?->toDateString(),
            'penalty_to' => $case->penalty_to?->toDateString(),
            'decision' => $case->decision,
            'decision_received_at' => $case->decision_received_at?->toIso8601String(),
            'withdrawn_reason' => $case->withdrawn_reason,
            'closed_at' => $case->closed_at?->toIso8601String(),
        ];
    }
}
