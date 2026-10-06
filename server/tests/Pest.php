<?php

use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RecruitmentPipeline;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Assistant\Assistant;
use App\Support\Ai\GeminiClient;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Attendance\SchedulePatternWriter;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| RBAC test helpers
|--------------------------------------------------------------------------
|
| Routes are permission-gated, so feature tests must authenticate as a user
| that actually holds the required permissions. These helpers seed the
| permission catalogue and build roles/users on demand.
|
*/

function seedPermissions(): void
{
    PermissionSyncer::sync();
}

/**
 * The organisation bound as the current tenant for this test, creating and binding
 * one on first use. Keeps every factory-built record (and the acting user) in the
 * same tenant so scoped queries behave as they would in a real request.
 */
function testOrganization(): Organization
{
    $tenancy = app(Tenancy::class);

    if (! $tenancy->check()) {
        $tenancy->set(Organization::factory()->create());
    }

    return $tenancy->organization();
}

/**
 * Create a role (in the current test tenant) granting the given permission names.
 *
 * @param  list<string>  $permissions
 */
function makeRole(string $name, array $permissions = [], bool $system = false): Role
{
    seedPermissions();
    testOrganization();

    $role = Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucwords(str_replace('-', ' ', $name)), 'is_system' => $system],
    );

    $role->permissions()->sync(
        Permission::whereIn('name', $permissions)->pluck('id')
    );

    return $role;
}

/**
 * Authenticate as a Super Admin (bypasses every gate) and return the user.
 */
function actingAsSuperAdmin(): User
{
    $role = makeRole(Role::SUPER_ADMIN, [], true);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Authenticate as a user holding exactly the given permissions.
 *
 * @param  list<string>  $permissions
 */
function actingAsUserWith(array $permissions): User
{
    $role = makeRole('test-role-'.Str::random(8), $permissions);
    $user = User::factory()->create();
    $user->roles()->attach($role);

    test()->actingAs($user);

    return $user;
}

/**
 * Assert the last response flashed a toast of the given level, optionally
 * containing a fragment of copy.
 *
 * Toasts are the app's feedback contract: the server flashes
 * `Inertia::flash('toast', ['type' => …, 'message' => …])`, which lands in the
 * session under `inertia.flash_data` and is rendered by `use-flash-toast.ts`.
 * Tests assert it here rather than reaching for that key by hand, so the whole
 * suite moves together if the transport ever changes.
 *
 * @param  'success'|'error'|'warning'|'info'  $type
 */
function assertToast(string $type, ?string $contains = null): void
{
    $toast = session('inertia.flash_data.toast');

    expect($toast)->not->toBeNull('No toast was flashed.')
        ->and($toast['type'] ?? null)->toBe($type);

    if ($contains !== null) {
        expect($toast['message'] ?? '')->toContain($contains);
    }
}

/**
 * The current test tenant's default recruitment pipeline (the classic 6-stage
 * flow), creating one if it doesn't already have one. Call after
 * `actingAsSuperAdmin()`/`actingAsUserWith()` so it lands in the acting user's
 * organisation, not a throwaway one of its own.
 */
function seedDefaultPipeline(): RecruitmentPipeline
{
    $organization = testOrganization();

    return RecruitmentPipeline::where('organization_id', $organization->id)->where('is_default', true)->first()
        ?? RecruitmentPipeline::factory()->withStandardStages()->create([
            'organization_id' => $organization->id,
            'is_default' => true,
        ]);
}

/*
|--------------------------------------------------------------------------
| Attendance helpers
|--------------------------------------------------------------------------
|
| Shared by the capture, devices and end-of-day tests: a Mon–Fri 08:00–17:00
| worker in Asia/Manila, and a day entered through the engine.
|
*/

/** A Mon–Fri 08:00–17:00 schedule, judged by settings laid over the fallback. */
function dayShiftSchedule(array $policy = []): WorkSchedule
{
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $schedule = WorkSchedule::create(['name' => 'Day Shift', 'type' => 'fixed', 'grace_minutes' => 0, 'cycle_length_days' => 7]);

    app(SchedulePatternWriter::class)->write($schedule, array_map(fn (int $i): array => [
        'is_rest_day' => $i >= 5,
        'segments' => [['start' => '08:00', 'end' => '17:00']],
        'required_minutes' => 480,
    ], range(0, 6)));

    if ($policy !== []) {
        AttendancePolicy::create([
            'name' => 'Needs approval',
            'settings' => AttendancePolicySettings::fromArray($policy)->toArray(),
            'settings_version' => AttendancePolicySettings::VERSION,
            'is_default' => true,
        ]);
    }

    return $schedule->refresh();
}

/** An employee on the day shift, optionally the signed-in user's own record. */
function dayShiftWorker(?User $user = null, array $policy = []): Employee
{
    $schedule = dayShiftSchedule($policy);
    $employee = Employee::factory()->create(['work_schedule_id' => null, 'user_id' => $user?->id]);
    app(ScheduleAssigner::class)->assign($employee, $schedule, '2026-09-01');

    return $employee->refresh();
}

/** A day entered through the engine, as HR would. */
function workedDay(Employee $employee, string $date, array $times): AttendanceRecord
{
    $clock = app(AttendanceClock::class);
    $record = $clock->openRecord($employee, $date);
    $clock->applyManualPunches($record, $times, User::factory()->create()->id);

    return $record->refresh();
}

/*
|--------------------------------------------------------------------------
| Assistant helpers
|--------------------------------------------------------------------------
|
| A stand-in for Gemini that plays a script back, one response per request,
| and keeps what it was sent — so no test ever spends a real model call.
|
*/

/**
 * Bind a scripted model behind the real assistant and return it.
 *
 * Each script entry is either the `parts` of one response, or
 * `['parts' => …, 'finish' => 'SAFETY', 'usage' => [...]]`. Once the script
 * runs out, the model answers "Okay." in text.
 *
 * @param  list<array<int|string, mixed>>  $script
 */
function fakeAssistantModel(array $script = []): GeminiClient
{
    $model = new class(null, 'stub') extends GeminiClient
    {
        /** @var list<array<int|string, mixed>> */
        public array $script = [];

        /** @var list<array{contents: array<int, mixed>, tools: array<int, mixed>, system: string}> */
        public array $sent = [];

        public function configured(): bool
        {
            return true;
        }

        public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
        {
            $this->sent[] = ['contents' => $contents, 'tools' => $functionDeclarations, 'system' => (string) $systemInstruction];
            $entry = array_shift($this->script) ?? [['text' => 'Okay.']];
            $parts = array_key_exists('parts', $entry) ? $entry['parts'] : $entry;

            return [
                'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $entry['finish'] ?? 'STOP']],
                'usageMetadata' => $entry['usage'] ?? ['promptTokenCount' => 1000, 'candidatesTokenCount' => 20],
            ];
        }

        /** The tool names offered on the given request. */
        public function toolNames(int $request = 0): array
        {
            return array_map(fn (array $d): string => $d['name'], $this->sent[$request]['tools'] ?? []);
        }
    };

    $model->script = $script;
    app()->instance(GeminiClient::class, $model);
    app()->forgetInstance(Assistant::class);

    return $model;
}

/** One function call, as a model part. */
function modelCall(string $name, array $args = []): array
{
    return ['functionCall' => ['name' => $name, 'args' => $args]];
}
