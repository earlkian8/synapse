<?php

namespace App\Support\Offboarding;

use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\ResignationRequest;
use App\Support\OffboardingProvisioner;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;

/**
 * One employee's own view of leaving (ADR 0076) — what My resignation on the
 * web and the Resignation screen in the app both show, built in one place so
 * they cannot disagree:
 *
 *  - the resignations they filed, and whether they may file one now;
 *  - their exit, once there is one: its dates and their clearance, item by
 *    item, so they know what each department still needs from them;
 *  - their exit interview, and whether it waits for them;
 *  - whether their Certificate of Employment is ready.
 *
 * The exit's reason is HR's record and is not shown here; their own
 * resignation's reason is, because they wrote it.
 */
final class MyExit
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Employee $employee): array
    {
        if ($employee === null) {
            return [
                'has_employee' => false,
                'can_resign' => false,
                'notice_days' => ResignationRequest::NOTICE_DAYS,
                'today' => OrganizationClock::today(),
                'resignations' => [],
                'exit' => null,
                'interview' => null,
            ];
        }

        $resignations = ResignationRequest::query()
            ->where('employee_id', $employee->id)
            ->with('decider:id,first_name,middle_name,last_name,suffix')
            ->latest('id')
            ->get();

        $case = $employee->offboardingCase()
            ->with([
                'clearanceItems' => fn ($query) => $query->with('department:id,name'),
                'exitInterview',
            ])
            ->first();

        $left = in_array($employee->employment_status, ['resigned', 'terminated'], true);

        return [
            'has_employee' => true,
            'can_resign' => ! $left
                && ! ($case?->isActive() ?? false)
                && ! $resignations->contains(fn (ResignationRequest $r): bool => $r->isPending()),
            'notice_days' => ResignationRequest::NOTICE_DAYS,
            'today' => OrganizationClock::today(),
            'resignations' => $resignations->map(fn (ResignationRequest $r): array => self::resignation($r))->values()->all(),
            'exit' => $case !== null && $case->status !== 'cancelled' ? self::exit($case) : null,
            'interview' => $case?->exitInterview !== null && $case->status !== 'cancelled'
                ? self::interview($case->exitInterview)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resignation(ResignationRequest $resignation): array
    {
        return [
            'id' => $resignation->hashid,
            'status' => $resignation->status,
            'proposed_last_day' => $resignation->proposed_last_day?->toDateString(),
            'reason' => $resignation->reason,
            'filed_on' => $resignation->created_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($resignation->created_at)) : null,
            'notice_days' => $resignation->noticeDays(),
            'decided_on' => $resignation->decided_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($resignation->decided_at)) : null,
            'decision_note' => $resignation->decision_note,
            'decided_by' => $resignation->decider?->full_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function exit(OffboardingCase $case): array
    {
        $items = $case->clearanceItems;
        $cleared = $items->where('status', 'cleared')->count();

        return [
            'id' => $case->hashid,
            'type' => $case->type,
            'status' => $case->status,
            'notice_date' => $case->notice_date?->toDateString(),
            'last_working_day' => $case->last_working_day?->toDateString(),
            'completed_on' => $case->completed_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($case->completed_at)) : null,
            'clearance' => [
                'total' => $items->count(),
                'cleared' => $cleared,
                'flagged' => $items->where('status', 'flagged')->count(),
                'status' => OffboardingProvisioner::clearanceStatus($items->count(), $cleared),
            ],
            'items' => $items->map(fn (ClearanceItem $item): array => [
                'id' => $item->id,
                'item' => $item->item,
                'department' => $item->department?->name,
                'status' => $item->status,
                // A flag's reason is what the person has to sort out.
                'remarks' => $item->status === 'flagged' ? $item->remarks : null,
                'cleared_on' => $item->cleared_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($item->cleared_at)) : null,
            ])->values()->all(),
            'certificate' => [
                'ready' => OffboardingDocuments::certificateReady($case),
                'number' => $case->certificate_no,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function interview(ExitInterview $interview): array
    {
        return [
            'status' => $interview->status,
            'sent_on' => $interview->sent_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($interview->sent_at)) : null,
            'submitted_on' => $interview->submitted_at !== null ? OrganizationClock::localDate(CarbonImmutable::instance($interview->submitted_at)) : null,
            'can_answer' => $interview->isPending(),
        ];
    }
}
