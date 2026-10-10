<?php

use App\Models\Applicant;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\Event;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OffboardingCase;
use App\Models\OnboardingCase;
use App\Models\Organization;
use App\Models\PerformanceEvaluation;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Support\Search\GlobalSearch;
use App\Support\Search\RecordSource;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Every kind of record global search finds (ADR 0069), each answering only to
| the permission its own screen checks, and each linking to a route that checks
| that same permission.
*/

/** The groups a search answered, keyed by kind. */
function gsSearch(string $query): array
{
    return collect(test()->getJson('/search?q='.urlencode($query))->assertOk()->json('groups'))
        ->keyBy('key')
        ->all();
}

/** One record of every searchable kind, each carrying the word "Zephyrine". */
function gsEverything(): Employee
{
    testOrganization();
    seedDefaultPipeline();

    $employee = Employee::factory()->create(['first_name' => 'Zephyrine', 'middle_name' => null, 'last_name' => 'Okonkwo', 'suffix' => null]);
    $posting = JobPosting::factory()->create(['title' => 'Zephyrine Analyst']);
    $applicant = Applicant::factory()->create(['first_name' => 'Zephyrine', 'last_name' => 'Adeyemi']);
    JobApplication::factory()->create(['applicant_id' => $applicant->id, 'job_posting_id' => $posting->id]);
    OnboardingCase::factory()->create(['employee_id' => $employee->id]);
    OffboardingCase::factory()->create(['employee_id' => $employee->id]);
    LeaveRequest::factory()->create(['employee_id' => $employee->id]);
    PerformanceEvaluation::create([
        'employee_id' => $employee->id,
        'evaluation_period_id' => EvaluationPeriod::factory()->create(['name' => 'Q3 2026'])->id,
        'status' => 'draft',
    ]);
    TrainingProgram::create(['name' => 'Zephyrine Leadership', 'start_date' => today(), 'end_date' => today()->addWeek()]);
    Event::create(['title' => 'Zephyrine Townhall', 'type' => 'meeting', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    Department::factory()->create(['name' => 'Zephyrine Operations']);
    User::factory()->create(['first_name' => 'Zephyrine', 'last_name' => 'Login']);
    makeRole('zephyrine-reviewers');

    return $employee;
}

const GS_RECORD_KINDS = [
    'employees' => 'employees.view',
    'applicants' => 'recruitment.view',
    'job-postings' => 'recruitment.view',
    'onboarding' => 'onboarding.view',
    'offboarding' => 'offboarding.view',
    'leave' => 'leave.view',
    'appraisals' => 'performance.view',
    'training' => 'training.view',
    'events' => 'events.view',
    'departments' => 'setup.departments.view',
    'users' => 'users.view',
    'roles' => 'roles.view',
];

test('every kind of record is found, in a fixed order', function () {
    gsEverything();
    actingAsSuperAdmin();

    $keys = array_keys(gsSearch('zephyrine'));
    $records = array_values(array_intersect($keys, array_keys(GS_RECORD_KINDS)));

    expect($records)->toBe(array_keys(GS_RECORD_KINDS));
});

test('each kind answers only to its own permission', function (string $key, string $permission) {
    gsEverything();
    actingAsUserWith([$permission]);

    $found = array_intersect(array_keys(gsSearch('zephyrine')), array_keys(GS_RECORD_KINDS));
    $expected = array_keys(array_filter(GS_RECORD_KINDS, fn (string $p): bool => $p === $permission));

    expect(array_values($found))->toContain($key)
        ->and(array_diff($found, $expected))->toBe([]);
})->with(fn () => collect(GS_RECORD_KINDS)->map(fn (string $p, string $k) => [$k, $p])->all());

test('every record link opens a route guarded by its source’s permission', function () {
    gsEverything();
    actingAsSuperAdmin();

    $sources = collect(app(GlobalSearch::class)->sources())
        ->filter(fn ($source) => $source instanceof RecordSource)
        ->keyBy(fn (RecordSource $source) => $source->key());

    expect($sources->keys()->all())->toEqualCanonicalizing(array_keys(GS_RECORD_KINDS));

    foreach (gsSearch('zephyrine') as $key => $group) {
        if (! $sources->has($key)) {
            continue;
        }

        foreach ($group['items'] as $item) {
            $route = Route::getRoutes()->match(Request::create($item['href']));

            expect(in_array('can:'.$sources[$key]->permissionName(), $route->gatherMiddleware(), true))
                ->toBeTrue("{$key}: {$item['href']} is not guarded by its source's permission");
        }
    }
});

test('the links say which record to open', function () {
    $employee = gsEverything();
    actingAsSuperAdmin();
    $groups = gsSearch('zephyrine');

    $application = JobApplication::query()->with('jobPosting')->firstOrFail();
    $leave = LeaveRequest::query()->firstOrFail();

    expect($groups['applicants']['items'][0]['href'])->toBe('/recruitment/'.$application->jobPosting->hashid.'?open='.$application->id)
        ->and($groups['leave']['items'][0]['href'])->toBe('/leave?status=all&search='.urlencode($employee->employee_no).'&open='.$leave->hashid)
        ->and($groups['onboarding']['items'][0]['href'])->toBe('/onboarding/'.OnboardingCase::query()->firstOrFail()->hashid)
        ->and($groups['departments']['items'][0]['href'])->toBe('/setup/departments?open='.Department::query()->firstOrFail()->id);
});

test('an appraisal is found by the person and the cycle together', function () {
    $employee = gsEverything();
    PerformanceEvaluation::create([
        'employee_id' => $employee->id,
        'evaluation_period_id' => EvaluationPeriod::factory()->create(['name' => 'Q4 2026'])->id,
        'status' => 'draft',
    ]);
    actingAsUserWith(['performance.view']);

    $items = gsSearch('zephyrine q3')['appraisals']['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['subtitle'])->toContain('Q3 2026');
});

test('what somebody wrote as a reason for leave is never searched', function () {
    actingAsUserWith(['leave.view', 'offboarding.view']);
    LeaveRequest::factory()->create(['reason' => 'Chemotherapy sessions']);
    OffboardingCase::factory()->create(['reason' => 'Chemotherapy, stepping back']);

    expect(gsSearch('chemotherapy'))->not->toHaveKeys(['leave', 'offboarding']);
});

test('users from another company are never found', function () {
    actingAsUserWith(['users.view']);
    $home = testOrganization();

    app(Tenancy::class)->runFor(
        Organization::factory()->create(),
        fn () => User::factory()->create(['first_name' => 'Quillon', 'last_name' => 'Stranger']),
    );
    app(Tenancy::class)->set($home);

    expect(gsSearch('quillon'))->not->toHaveKey('users');
});

test('an offboarding case with no last day yet is still found', function () {
    actingAsUserWith(['offboarding.view']);
    $employee = Employee::factory()->create(['first_name' => 'Ysolde', 'last_name' => 'Pending']);
    OffboardingCase::factory()->create(['employee_id' => $employee->id, 'last_working_day' => null]);

    $items = gsSearch('ysolde')['offboarding']['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['subtitle'])->not->toContain('last day');
});

test('leave and appraisals of an archived employee are left out', function () {
    actingAsUserWith(['leave.view', 'performance.view']);
    $archived = Employee::factory()->create();
    LeaveRequest::factory()->create([
        'employee_id' => $archived->id,
        'leave_type_id' => LeaveType::factory()->create(['name' => 'Xenial Leave'])->id,
    ]);
    PerformanceEvaluation::create([
        'employee_id' => $archived->id,
        'evaluation_period_id' => EvaluationPeriod::factory()->create(['name' => 'Xenial Cycle'])->id,
        'status' => 'draft',
    ]);
    $archived->delete();

    expect(gsSearch('xenial'))->not->toHaveKeys(['leave', 'appraisals']);
});
