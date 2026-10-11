<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offboarding\DecideResignationRequest;
use App\Models\Department;
use App\Models\OffboardingProgram;
use App\Models\ResignationRequest;
use App\Support\Offboarding\ResignationWorkflow;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resignations employees filed themselves (ADR 0076), for HR to accept — the
 * exit starts — or decline with a note. Waiting ones first. Thin: every
 * decision is {@see ResignationWorkflow}'s.
 */
class ResignationController extends Controller
{
    use OffboardingResponses;

    public function __construct(private readonly ResignationWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $resignations = ResignationRequest::query()
            ->whereHas('employee')
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'decider:id,first_name,middle_name,last_name,suffix',
                'offboardingCase:id,status',
            ])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->get();

        $since = CarbonImmutable::parse(OrganizationClock::today())->subDays(30);

        return Inertia::render('offboarding/resignations', [
            'resignations' => $resignations->map(fn (ResignationRequest $r): array => [
                'id' => $r->hashid,
                'status' => $r->status,
                'employee' => [
                    'id' => $r->employee->id,
                    'full_name' => $r->employee->full_name,
                    'initials' => $r->employee->initials(),
                    'employee_no' => $r->employee->employee_no,
                    'photo' => $r->employee->photo_url,
                    'department' => $r->employee->department?->name,
                    'department_id' => $r->employee->department_id,
                    'position' => $r->employee->position?->title,
                ],
                'filed_on' => OrganizationClock::localDate(CarbonImmutable::instance($r->created_at)),
                'proposed_last_day' => $r->proposed_last_day->toDateString(),
                'notice_days' => $r->noticeDays(),
                'reason' => $r->reason,
                'decided_on' => $r->decided_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($r->decided_at)) : null,
                'decided_by' => $r->decider?->full_name,
                'decision_note' => $r->decision_note,
                'case_id' => $r->offboardingCase?->hashid,
            ])->values()->all(),
            'stats' => [
                'pending' => $resignations->where('status', 'pending')->count(),
                'short_notice' => $resignations->filter(fn (ResignationRequest $r): bool => $r->isPending() && $r->noticeDays() < ResignationRequest::NOTICE_DAYS)->count(),
                'accepted_30d' => $resignations->filter(fn (ResignationRequest $r): bool => $r->status === 'accepted' && $r->decided_at?->gte($since))->count(),
                'withdrawn_30d' => $resignations->filter(fn (ResignationRequest $r): bool => $r->status === 'withdrawn' && $r->decided_at?->gte($since))->count(),
            ],
            'options' => [
                'programs' => OffboardingProgram::options(),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
            ],
            'notice_days' => ResignationRequest::NOTICE_DAYS,
            'can' => ['manage' => $request->user()->can('offboarding.manage')],
            'nav' => $this->navCounts($request),
        ]);
    }

    public function decide(DecideResignationRequest $request, ResignationRequest $resignation): RedirectResponse
    {
        if ($request->validated('decision') === 'decline') {
            return $this->attempt(
                fn () => $this->workflow->decline($resignation, $request->user(), (string) $request->validated('note')),
                'Resignation declined. They have been told why.',
            );
        }

        $program = $request->filled('offboarding_program_id')
            ? OffboardingProgram::where('is_active', true)->find($request->integer('offboarding_program_id'))
            : null;

        return $this->attempt(
            fn () => $this->workflow->accept(
                $resignation,
                $request->user(),
                $request->date('last_working_day')->toDateString(),
                $program,
                $request->validated('note'),
            ),
            'Resignation accepted. Their clearance has started.',
        );
    }
}
