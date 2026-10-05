<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ActivityLog;
use App\Models\DataExport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('exports');
    Storage::fake('public');
});

/** Sign in holding the given permissions, as a member of the test workspace. */
function exporter(array $permissions = ['data-export.view', 'data-export.create', 'employees.view', 'setup.departments.view']): User
{
    $user = actingAsUserWith($permissions);
    OrganizationProvisioner::addMember(testOrganization(), $user, default: true);

    return $user;
}

/** A finished archive of this workspace, owned by the given user. */
function readyExport(User $owner, array $attributes = []): DataExport
{
    Storage::disk('exports')->put('organization-'.testOrganization()->id.'/archive.zip', 'zip-bytes');

    return DataExport::create([
        'requested_by' => $owner->id,
        'status' => DataExport::READY,
        'format' => 'csv',
        'datasets' => ['employees'],
        'include_files' => false,
        'disk' => 'exports',
        'path' => 'organization-'.testOrganization()->id.'/archive.zip',
        'filename' => 'synapse-export.zip',
        'size_bytes' => 9,
        'completed_at' => now(),
        'expires_at' => now()->addDays(DataExport::RETENTION_DAYS),
        ...$attributes,
    ]);
}

// ── The screen ───────────────────────────────────────────────────────────────

test('the screen lists only the datasets the viewer may see', function () {
    exporter();

    $this->get(route('system.data-export.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/data-export/index')
            ->where('datasets', fn ($datasets) => collect($datasets)->pluck('key')->sort()->values()->all() === ['employees', 'structure'])
            ->where('can.create', true)
            ->where('retention_days', DataExport::RETENTION_DAYS)
            ->has('exports', 0));
});

test('the screen counts the records each dataset holds', function () {
    exporter();
    Employee::factory()->count(3)->create();
    Department::factory()->count(2)->create();

    $this->get(route('system.data-export.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('datasets', function ($datasets) {
                $byKey = collect($datasets)->keyBy('key');

                return $byKey['employees']['records'] === 3 && $byKey['structure']['records'] === 2;
            }));
});

test('the screen is closed without the view permission', function () {
    exporter(['employees.view']);

    $this->get(route('system.data-export.index'))->assertForbidden();
});

test('a viewer without the create permission sees the history but cannot export', function () {
    $owner = exporter();
    readyExport($owner);

    exporter(['data-export.view', 'employees.view']);

    $this->get(route('system.data-export.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)
            ->has('exports', 1)
            ->where('exports.0.can_download', false)
            ->where('exports.0.can_delete', false));

    $this->post(route('system.data-export.store'), ['datasets' => ['employees'], 'format' => 'csv'])
        ->assertForbidden();
});

// ── Requesting ───────────────────────────────────────────────────────────────

test('requesting an export builds the archive and tells the requester', function () {
    $user = exporter();
    Employee::factory()->count(2)->create();

    $this->post(route('system.data-export.store'), [
        'datasets' => ['employees', 'structure'],
        'format' => 'csv',
        'include_files' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $export = DataExport::sole();

    expect($export->status)->toBe(DataExport::READY)
        ->and($export->requested_by)->toBe($user->id)
        ->and($export->datasets)->toBe(['employees', 'structure'])
        ->and($export->size_bytes)->toBeGreaterThan(0)
        ->and($export->expires_at->isAfter(now()->addDays(DataExport::RETENTION_DAYS - 1)))->toBeTrue()
        ->and($export->summary['datasets']['employees']['tables']['employees'])->toBe(2);

    Storage::disk('exports')->assertExists($export->path);

    expect($user->notifications()->sole()->data['title'])->toBe('Your data export is ready');
    expect(ActivityLog::query()->where('log_name', 'data-export')->pluck('event')->all())
        ->toContain('created');
});

test('a dataset the requester may not see is refused', function () {
    exporter(['data-export.view', 'data-export.create', 'employees.view']);

    $this->post(route('system.data-export.store'), ['datasets' => ['employees', 'attendance'], 'format' => 'csv'])
        ->assertSessionHasErrors('datasets.1');

    expect(DataExport::count())->toBe(0);
});

test('an export needs at least one dataset and a known format', function () {
    exporter();

    $this->post(route('system.data-export.store'), ['datasets' => [], 'format' => 'xlsx'])
        ->assertSessionHasErrors(['datasets', 'format']);
});

test('only one export is prepared at a time in a workspace', function () {
    $owner = exporter();
    DataExport::create([
        'requested_by' => $owner->id,
        'status' => DataExport::BUILDING,
        'format' => 'csv',
        'datasets' => ['employees'],
        'started_at' => now(),
    ]);

    $this->post(route('system.data-export.store'), ['datasets' => ['employees'], 'format' => 'csv']);

    assertToast('error', 'already being prepared');
    expect(DataExport::count())->toBe(1);
});

test('an export whose build died reads as failed and no longer holds up the next', function () {
    $user = exporter();
    DataExport::create([
        'requested_by' => $user->id,
        'status' => DataExport::BUILDING,
        'format' => 'csv',
        'datasets' => ['employees'],
        'started_at' => now()->subMinutes(DataExport::STALE_AFTER_MINUTES + 1),
    ]);

    $this->get(route('system.data-export.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('exports.0.status', DataExport::FAILED)
            ->where('exports.0.can_delete', true));

    $this->post(route('system.data-export.store'), ['datasets' => ['employees'], 'format' => 'csv'])
        ->assertSessionHasNoErrors();

    assertToast('success');
});

test('checking on an export in progress does not recount every dataset', function () {
    exporter();

    $version = app(HandleInertiaRequests::class)->version(request());
    $counted = [];
    DB::listen(function ($query) use (&$counted): void {
        if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'employees')) {
            $counted[] = $query->sql;
        }
    });

    $this->get(route('system.data-export.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'system/data-export/index',
        'X-Inertia-Partial-Data' => 'exports',
    ])->assertOk()->assertJsonPath('props.exports', [])->assertJsonMissingPath('props.datasets');

    expect($counted)->toBe([]);
});

// ── Downloading ──────────────────────────────────────────────────────────────

test('the requester downloads their archive, and the download is recorded', function () {
    $user = exporter();
    $export = readyExport($user);

    $this->get(route('system.data-export.download', $export))
        ->assertOk()
        ->assertDownload('synapse-export.zip');

    expect($export->fresh()->download_count)->toBe(1)
        ->and($export->fresh()->last_downloaded_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('log_name', 'data-export')->where('event', 'downloaded')->exists())->toBeTrue();
});

test('nobody else can download an archive, whatever their access', function () {
    $owner = exporter();
    $export = readyExport($owner);

    exporter();

    $this->get(route('system.data-export.download', $export))->assertForbidden();
});

test('an archive cannot be downloaded once the requester loses access to what is in it', function () {
    $user = exporter(['data-export.view', 'data-export.create', 'employees.view']);
    $export = readyExport($user);

    $user->roles()->first()->permissions()->where('name', 'employees.view')->detach();
    $user->forgetCachedPermissions();

    $this->get(route('system.data-export.download', $export))->assertForbidden();
});

test('an expired archive cannot be downloaded', function () {
    $user = exporter();
    $export = readyExport($user, ['expires_at' => now()->subMinute()]);

    $this->get(route('system.data-export.download', $export))->assertNotFound();
});

test('an archive whose file has gone is marked expired rather than served', function () {
    $user = exporter();
    $export = readyExport($user);
    Storage::disk('exports')->delete($export->path);

    $this->get(route('system.data-export.download', $export))->assertRedirect();

    assertToast('error', 'no longer available');
    expect($export->fresh()->status)->toBe(DataExport::EXPIRED);
});

// ── Deleting ─────────────────────────────────────────────────────────────────

test('deleting an export removes its archive', function () {
    $user = exporter();
    $export = readyExport($user);

    $this->delete(route('system.data-export.destroy', $export))->assertRedirect();

    expect(DataExport::count())->toBe(0);
    Storage::disk('exports')->assertMissing($export->path);
    expect(ActivityLog::query()->where('log_name', 'data-export')->where('event', 'deleted')->exists())->toBeTrue();
});

test('an export still being prepared cannot be deleted', function () {
    $user = exporter();
    $export = DataExport::create([
        'requested_by' => $user->id,
        'status' => DataExport::BUILDING,
        'format' => 'csv',
        'datasets' => ['employees'],
        'started_at' => now(),
    ]);

    $this->delete(route('system.data-export.destroy', $export));

    assertToast('error');
    expect($export->fresh())->not->toBeNull();
});

// ── Tenancy ──────────────────────────────────────────────────────────────────

test("another workspace's exports are out of reach", function () {
    $user = exporter();

    $other = Organization::factory()->create();
    $foreign = app(Tenancy::class)->runFor($other, fn () => DataExport::create([
        'organization_id' => $other->id,
        'requested_by' => $user->id,
        'status' => DataExport::READY,
        'format' => 'csv',
        'datasets' => ['employees'],
        'disk' => 'exports',
        'path' => 'organization-'.$other->id.'/archive.zip',
        'filename' => 'theirs.zip',
        'expires_at' => now()->addDay(),
    ]));

    // As a real request: no tenant bound until the middleware binds it.
    app(Tenancy::class)->forget();

    $this->get(route('system.data-export.index'))
        ->assertInertia(fn (Assert $page) => $page->has('exports', 0));

    app(Tenancy::class)->forget();
    $this->get(route('system.data-export.download', $foreign))->assertNotFound();

    app(Tenancy::class)->forget();
    $this->delete(route('system.data-export.destroy', $foreign))->assertNotFound();

    expect(DataExport::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});
