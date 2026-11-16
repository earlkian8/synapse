<?php

namespace App\Support\Discipline;

use App\Models\ConductOffense;
use App\Models\DisciplinaryCase;
use App\Support\Documents\DocumentRenderer;
use App\Support\OrganizationClock;
use Illuminate\Support\Str;

/**
 * A disciplinary case's two notices as PDFs under the company's letterhead
 * (ADR 0105) — the twin notices D.O. 147-15 asks to be served in writing:
 *
 *  - the **notice to explain**: the specific charge, the facts in detail, the
 *    rule and the Labor Code ground it rests on, the day an answer is due, the
 *    right to a hearing and to a representative;
 *  - the **notice of decision**: that every circumstance was considered, the
 *    finding, the penalty and its dates, and why.
 *
 * Each has a line for the employee to sign on receipt, beside the date they
 * acknowledged it in the app, if they did.
 */
class DisciplineDocuments
{
    public function __construct(private readonly DocumentRenderer $documents) {}

    public function notice(DisciplinaryCase $case): string
    {
        return $this->documents->render('documents.notice-to-explain', $this->data($case));
    }

    public function decision(DisciplinaryCase $case): string
    {
        return $this->documents->render('documents.notice-of-decision', $this->data($case));
    }

    public static function filename(string $kind, DisciplinaryCase $case): string
    {
        return $kind.'-'.Str::lower($case->reference).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(DisciplinaryCase $case): array
    {
        $case->loadMissing([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'offense',
        ]);

        return [
            'case' => $case,
            'employee' => $case->employee,
            'offense' => $case->offense,
            'ground' => $case->offense !== null ? (ConductOffense::GROUNDS[$case->offense->ground] ?? null) : null,
            'penalty' => $case->penalty !== null ? DisciplinaryCase::PENALTIES[$case->penalty] : null,
            'issued' => $case->notice_issued_at !== null ? OrganizationClock::local($case->notice_issued_at) : null,
            'decided' => $case->decided_at !== null ? OrganizationClock::local($case->decided_at) : null,
            'hearing' => $case->hearing_at !== null ? OrganizationClock::local($case->hearing_at) : null,
            'received' => $case->notice_received_at !== null ? OrganizationClock::local($case->notice_received_at) : null,
            'decisionReceived' => $case->decision_received_at !== null ? OrganizationClock::local($case->decision_received_at) : null,
        ];
    }
}
