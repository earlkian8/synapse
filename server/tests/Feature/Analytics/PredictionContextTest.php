<?php

use App\Models\AttendanceRecord;
use App\Models\AttritionRiskRun;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\EmployeePromotion;
use App\Models\EvaluationPeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\PerformanceForecastRun;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PromotionReadinessRun;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Ml\PredictionContext;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;

/**
 * What HR weighs beside one person's prediction (ADR 0099): the breakdown dialog
 * fetches the person's trend across runs, their other predictions and their record.
 * Each block is gated by the module it comes from, and another workspace's person
 * is out of reach.
 */

/** A completed attrition run holding one score for `$employee`. */
function contextAttritionRun(Employee $employee, float $score, string $tier, string $at): AttritionRiskRun
{
    $run = AttritionRiskRun::create([
        'status' => 'completed', 'employees_scored' => 1, 'high_count' => (int) ($tier === 'high'),
        'medium_count' => (int) ($tier === 'medium'), 'low_count' => (int) ($tier === 'low'),
        'average_score' => $score, 'average_confidence' => 1,
    ]);
    $run->forceFill(['created_at' => $at])->save();
    $run->scores()->create([
        'employee_id' => $employee->id, 'probability' => $score / 100, 'score' => $score,
        'tier' => $tier, 'confidence' => 1, 'factors' => [],
    ]);

    return $run;
}

/** Every permission the context reads from, plus the three surfaces. */
function contextViewer(): void
{
    actingAsUserWith([
        'analytics.attrition.view', 'analytics.promotion.view', 'analytics.performance.view',
        'employees.view', 'attendance.view', 'leave.view', 'performance.view',
        'training.view', 'awards.view', 'offboarding.view',
    ]);
}

test('the dialog gets the person’s trend across runs, oldest first', function () {
    contextViewer();
    $employee = Employee::factory()->create(['employment_status' => 'active']);
    $first = contextAttritionRun($employee, 41.0, 'medium', '2026-08-01 09:00:00');
    contextAttritionRun($employee, 67.5, 'high', '2026-10-01 09:00:00');
    AttritionRiskRun::create(['status' => 'failed', 'employees_scored' => 0, 'high_count' => 0, 'medium_count' => 0, 'low_count' => 0])
        ->scores()->create(['employee_id' => $employee->id, 'probability' => 0.1, 'score' => 10, 'tier' => 'low', 'confidence' => 1, 'factors' => []]);

    $this->getJson(route('analytics.attrition.people.show', $employee))
        ->assertOk()
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.run', $first->hashid)
        ->assertJsonPath('history.0.score', 41)
        ->assertJsonPath('history.1.tier', 'high');
});

test('a person’s other predictions come with it, each only for those who may see that screen', function () {
    actingAsUserWith(['analytics.attrition.view', 'analytics.performance.view']);
    $employee = Employee::factory()->create(['employment_status' => 'active']);
    contextAttritionRun($employee, 70.0, 'high', '2026-10-01 09:00:00');

    $readiness = PromotionReadinessRun::create(['status' => 'completed', 'employees_scored' => 1, 'high_count' => 1, 'medium_count' => 0, 'low_count' => 0, 'average_score' => 88]);
    $readiness->scores()->create(['employee_id' => $employee->id, 'probability' => 0.3, 'score' => 88, 'tier' => 'high', 'factors' => [], 'features' => []]);
    $forecast = PerformanceForecastRun::create(['status' => 'completed', 'employees_scored' => 1, 'exceeds_count' => 1, 'on_track_count' => 0, 'below_count' => 0, 'average_rating' => 86]);
    $forecast->forecasts()->create(['employee_id' => $employee->id, 'predicted_rating' => 86.2, 'predicted_low' => 79, 'predicted_high' => 93, 'confidence' => 0.9, 'band' => 'exceeds', 'features' => [], 'history' => []]);

    $this->getJson(route('analytics.attrition.people.show', $employee))
        ->assertOk()
        // The surface being viewed is not a signal of itself, and readiness is
        // left out for someone who cannot open Promotion Readiness.
        ->assertJsonMissingPath('signals.attrition')
        ->assertJsonMissingPath('signals.promotion')
        ->assertJsonPath('signals.performance.score', 86.2)
        ->assertJsonPath('signals.performance.tier', 'exceeds')
        ->assertJsonPath('signals.performance.run', $forecast->hashid);
});

test('the record around the model’s inputs is gathered for a viewer who may see every module', function () {
    contextViewer();
    $today = CarbonImmutable::today();
    $from = Position::factory()->create(['title' => 'Analyst']);
    $to = Position::factory()->create(['title' => 'Senior Analyst']);
    $manager = Employee::factory()->create(['first_name' => 'Lina', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null]);
    $employee = Employee::factory()->create([
        'employment_status' => 'active', 'employment_type' => 'probationary',
        'date_hired' => $today->subYears(2)->toDateString(), 'manager_id' => $manager->id, 'position_id' => $to->id,
    ]);

    // Attendance last month: two days worked (one late), one absent.
    $day = $today->startOfMonth()->subMonth();
    AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => $day->toDateString(), 'status' => 'present', 'overtime_minutes' => 90]);
    AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => $day->addDay()->toDateString(), 'status' => 'late', 'late_minutes' => 25]);
    AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => $day->addDays(2)->toDateString(), 'status' => 'absent', 'worked_minutes' => 0]);

    $vacation = LeaveType::factory()->create(['name' => 'Vacation']);
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'status' => 'approved',
        'start_date' => $today->subMonths(2)->toDateString(), 'end_date' => $today->subMonths(2)->addDays(2)->toDateString(), 'days' => 3]);
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'status' => 'approved',
        'start_date' => $today->addMonth()->toDateString(), 'end_date' => $today->addMonth()->toDateString(), 'days' => 1]);
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'status' => 'pending',
        'start_date' => $today->addMonths(2)->toDateString(), 'end_date' => $today->addMonths(2)->toDateString(), 'days' => 1]);

    $period = EvaluationPeriod::factory()->create(['name' => 'FY 2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed']);
    $appraisal = PerformanceEvaluation::create([
        'employee_id' => $employee->id, 'evaluation_period_id' => $period->id,
        'overall_percent' => 78.0, 'overall_score' => 4.12, 'result_label' => 'Meets', 'status' => 'acknowledged', 'submitted_at' => now(),
    ]);
    foreach ([['goals', 'Goals', 60, 4], ['values', 'Values', 40, 3]] as $i => [$key, $name, $weight, $score]) {
        $appraisal->scores()->create([
            'label' => "Line {$i}", 'section_key' => $key, 'section_name' => $name, 'section_weight' => $weight,
            'weight' => 1, 'scale_type' => 'numeric', 'scale_min' => 1, 'scale_max' => 5, 'score' => $score, 'sort_order' => $i,
        ]);
    }

    EmployeePromotion::create(['employee_id' => $employee->id, 'from_position_id' => $from->id, 'to_position_id' => $to->id,
        'from_salary' => 20000, 'to_salary' => 26000, 'effective_date' => $today->subYear()->toDateString()]);

    $program = TrainingProgram::create(['name' => 'Excel for Analysts', 'start_date' => $today->subMonths(3), 'end_date' => $today->subMonths(3)->addWeek()]);
    TrainingEnrollment::create(['training_program_id' => $program->id, 'employee_id' => $employee->id, 'status' => 'completed', 'completed_at' => $today->subMonths(3)]);

    $spot = AwardType::create(['name' => 'Spot Award', 'is_active' => true]);
    EmployeeAward::create(['employee_id' => $employee->id, 'award_type_id' => $spot->id, 'awarded_on' => $today->subMonths(5)->toDateString()]);

    OffboardingCase::factory()->create(['employee_id' => $employee->id, 'type' => 'resignation', 'status' => 'initiated']);

    $json = $this->getJson(route('analytics.attrition.people.show', $employee))->assertOk()->json();

    expect($json['employment'])->toMatchArray([
        'employment_type' => 'probationary', 'position' => 'Senior Analyst', 'manager' => 'Lina Cruz',
        'profile_url' => route('employees.show', $employee),
    ]);
    expect($json['attendance']['months'])->toHaveCount(6)
        ->and($json['attendance']['months'][4])->toMatchArray([
            'label' => $day->format('M'), 'tracked' => 3, 'worked' => 2, 'absences' => 1, 'lates' => 1, 'late_minutes' => 25, 'overtime_hours' => 1.5,
        ]);
    expect($json['leave'])->toMatchArray(['days_12m' => 3, 'pending' => 1])
        ->and($json['leave']['by_type'][0])->toBe(['type' => 'Vacation', 'days' => 3, 'requests' => 1])
        ->and($json['leave']['upcoming'])->toHaveCount(1);
    // Goals 4/5 = 75%, Values 3/5 = 50%: which part of the work carried the result.
    expect($json['appraisals'][0])->toMatchArray(['period' => 'FY 2025', 'percent' => 78, 'result' => 'Meets'])
        ->and(collect($json['appraisals'][0]['sections'])->pluck('percent', 'name')->all())->toBe(['Goals' => 75, 'Values' => 50]);
    expect($json['promotions'][0])->toBe(['effective_date' => $today->subYear()->toDateString(), 'from' => 'Analyst', 'to' => 'Senior Analyst'])
        // Positions only: a promotion's pay never reaches the dialog.
        ->and(json_encode($json['promotions']))->not->toContain('26000');
    expect($json['training'])->toMatchArray(['completed_12m' => 1])
        ->and($json['training']['recent'][0]['program'])->toBe('Excel for Analysts');
    expect($json['awards'][0]['type'])->toBe('Spot Award');
    expect($json['offboarding'])->toMatchArray(['type' => 'resignation', 'status' => 'initiated']);
});

test('a viewer who may only see the screen gets the trend, but none of the other modules’ records', function () {
    actingAsUserWith(['analytics.attrition.view']);
    $employee = Employee::factory()->create(['employment_status' => 'active']);
    contextAttritionRun($employee, 55.0, 'medium', '2026-10-01 09:00:00');
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'status' => 'approved']);
    OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'initiated']);

    $this->getJson(route('analytics.attrition.people.show', $employee))
        ->assertOk()
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('signals', [])
        ->assertJsonPath('employment.profile_url', null)
        ->assertJsonPath('offboarding', null)
        ->assertJsonPath('attendance', null)
        ->assertJsonPath('leave', null)
        ->assertJsonPath('appraisals', null)
        ->assertJsonPath('promotions', null)
        ->assertJsonPath('training', null)
        ->assertJsonPath('awards', null);
});

test('a forecast’s trend carries what each period actually gave', function () {
    actingAsUserWith(['analytics.performance.view']);
    $employee = Employee::factory()->create(['employment_status' => 'active']);
    $period = EvaluationPeriod::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'closed']);
    $run = PerformanceForecastRun::create(['status' => 'completed', 'target_period_id' => $period->id, 'employees_scored' => 1, 'exceeds_count' => 0, 'on_track_count' => 1, 'below_count' => 0, 'average_rating' => 74]);
    $run->forecasts()->create(['employee_id' => $employee->id, 'predicted_rating' => 74.0, 'predicted_low' => 66, 'predicted_high' => 82, 'confidence' => 0.7, 'band' => 'on_track', 'features' => [], 'history' => []]);
    PerformanceEvaluation::create(['employee_id' => $employee->id, 'evaluation_period_id' => $period->id, 'overall_percent' => 80.5, 'overall_score' => 4.2, 'status' => 'submitted', 'submitted_at' => now()]);

    $this->getJson(route('analytics.performance-forecast.people.show', $employee))
        ->assertOk()
        ->assertJsonPath('history.0.score', 74)
        ->assertJsonPath('history.0.low', 66)
        ->assertJsonPath('history.0.period', 'FY 2026')
        ->assertJsonPath('history.0.actual', 80.5);
});

test('the context needs the screen’s own view permission', function () {
    actingAsUserWith(['analytics.promotion.view', 'employees.view']);
    $employee = Employee::factory()->create();

    $this->getJson(route('analytics.attrition.people.show', $employee))->assertForbidden();
    $this->getJson(route('analytics.promotion-readiness.people.show', $employee))->assertOk();
});

test('another workspace’s person is out of reach', function () {
    contextViewer();
    $stranger = app(Tenancy::class)->runFor(Organization::factory()->create(), fn (): Employee => Employee::factory()->create());
    // Resolve the workspace the way a real request does, not from the test's binding.
    app(Tenancy::class)->forget();

    $this->getJson(route('analytics.attrition.people.show', $stranger))->assertNotFound();
});

test('every surface the context knows has a view permission that exists', function () {
    seedPermissions();

    expect(Permission::whereIn('name', PredictionContext::SURFACES)->count())->toBe(3);
});
