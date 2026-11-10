<?php

use App\Models\AttritionRiskRun;
use App\Models\AttritionRiskScore;
use App\Models\AwardType;
use App\Models\CheckIn;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\CheckIns\CheckInPeriod;
use App\Support\Ml\AttritionRiskAssessor;
use App\Support\Ml\Graduation\AttritionGraduation;
use App\Support\Ml\Graduation\FieldCounts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| What only the organisation's own records hold (ADR 0102): each risk score is sent
| and stored with the person's team climate (their department's shown check-in
| result, never their own answers), time since recognition and leave taken — the
| signals an organisation's own model can learn from once it graduates.
*/

/** Fake the service, keeping what it was sent. */
function signalsFakeService(): void
{
    Http::fake([
        '*/predict/attrition' => fn (Request $request) => Http::response([
            'model' => 'attrition', 'model_version' => 'SVC@test',
            'results' => collect($request->data()['instances'])->map(fn (array $i): array => [
                'ref' => $i['ref'], 'probability' => 0.4, 'score' => 40.0, 'tier' => 'medium', 'factors' => [],
            ])->all(),
        ]),
    ]);
}

test('a score carries the team climate, time since recognition and leave taken, and never one person\'s answers', function () {
    actingAsSuperAdmin();
    signalsFakeService();
    $ops = Department::factory()->create();
    $me = Employee::factory()->create(['department_id' => $ops->id, 'employment_status' => 'active', 'date_hired' => now()->subYears(3)]);
    // My own check-in: rough. My five colleagues: good.
    CheckIn::create(['employee_id' => $me->id, 'department_id' => $ops->id, 'period' => CheckInPeriod::current()->toDateString(),
        'mood' => 1, 'satisfaction' => 1, 'energy' => 0, 'enthusiasm' => 0, 'immersion' => 0, 'leave_thoughts' => 5, 'submitted_at' => now()]);
    foreach (range(1, 5) as $_) {
        $colleague = Employee::factory()->create(['department_id' => $ops->id, 'employment_status' => 'active']);
        CheckIn::create(['employee_id' => $colleague->id, 'department_id' => $ops->id, 'period' => CheckInPeriod::current()->toDateString(),
            'mood' => 4, 'satisfaction' => 4, 'energy' => 4, 'enthusiasm' => 4, 'immersion' => 4, 'leave_thoughts' => 1, 'submitted_at' => now()]);
    }
    EmployeeAward::create(['employee_id' => $me->id, 'award_type_id' => AwardType::create(['name' => 'Spot', 'is_active' => true])->id,
        'awarded_on' => now()->subMonths(8)->toDateString(), 'reason' => 'Kept the line running']);
    LeaveRequest::create(['employee_id' => $me->id, 'leave_type_id' => LeaveType::factory()->create()->id, 'status' => 'approved',
        'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->subMonths(2)->addDays(2)->toDateString(), 'days' => 3]);

    app(AttritionRiskAssessor::class)->run(null);

    $features = AttritionRiskScore::query()->where('employee_id', $me->id)->sole()->features;
    // The team's figure, all six answers in it — mine is one of six, the same number
    // every colleague's score carries, which the results page already shows.
    expect($features['team_mood'])->toBe(3.5)
        ->and($features['team_leave_thoughts'])->toEqual(round(1 / 6, 4))
        ->and($features['months_since_recognition'])->toBeGreaterThanOrEqual(7.9)->toBeLessThanOrEqual(8.1)
        ->and($features['leave_days_365d'])->toEqual(3.0);
    Http::assertSent(fn (Request $request): bool => collect($request->data()['instances'] ?? [])
        ->contains(fn (array $i): bool => ($i['features']['team_mood'] ?? null) === 3.5));
    // Confidence counts the general model's inputs only.
    expect(AttritionRiskAssessor::KEY_FEATURES)->not->toContain('team_mood');
});

test('someone never recognised waits their whole tenure; a newer hire has no year of leave', function () {
    actingAsSuperAdmin();
    signalsFakeService();
    $new = Employee::factory()->create(['employment_status' => 'active', 'date_hired' => now()->subMonths(5), 'department_id' => null]);

    app(AttritionRiskAssessor::class)->run(null);

    $features = AttritionRiskScore::query()->where('employee_id', $new->id)->sole()->features;
    expect($features['months_since_recognition'])->toBeGreaterThanOrEqual(4.9)->toBeLessThanOrEqual(5.1)
        ->and($features)->not->toHaveKey('leave_days_365d')
        ->and($features)->not->toHaveKey('team_mood');
});

test('a team below five answers sends no climate at all', function () {
    actingAsSuperAdmin();
    signalsFakeService();
    $small = Department::factory()->create();
    $me = Employee::factory()->create(['department_id' => $small->id, 'employment_status' => 'active']);
    CheckIn::create(['employee_id' => $me->id, 'department_id' => $small->id, 'period' => CheckInPeriod::current()->toDateString(),
        'mood' => 1, 'satisfaction' => 1, 'energy' => 0, 'enthusiasm' => 0, 'immersion' => 0, 'leave_thoughts' => 5, 'submitted_at' => now()]);

    app(AttritionRiskAssessor::class)->run(null);

    expect(AttritionRiskScore::query()->where('employee_id', $me->id)->sole()->features)->not->toHaveKey('team_mood');
});

test('graduation trains on the signals too, and the panel lists them as kept for the organisation\'s own model', function () {
    actingAsSuperAdmin();
    signalsFakeService();
    Employee::factory()->create(['employment_status' => 'active', 'date_hired' => now()->subYears(2)]);
    app(AttritionRiskAssessor::class)->run(null);
    AttritionRiskScore::query()->update(['created_at' => now()->subYears(2)]);
    AttritionRiskRun::query()->update(['created_at' => now()->subYears(2)]);

    $graduation = app(AttritionGraduation::class);
    $rows = $graduation->trainingSet()->rows;
    $fields = collect($graduation->fields(new FieldCounts))->keyBy('key');

    expect($rows[0]['features'])->toHaveKey('months_since_recognition')
        ->and($fields['team_climate']['state'])->toBe('learned')
        ->and($fields['recognition']['state'])->toBe('learned')
        ->and($fields['leave_taken']['state'])->toBe('learned')
        ->and($fields)->not->toHaveKey('engagement');
});
