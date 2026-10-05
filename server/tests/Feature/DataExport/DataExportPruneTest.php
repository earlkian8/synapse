<?php

use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
});

/** An export of the given organisation, written without a bound tenant (as the scheduler sees it). */
function exportOf(Organization $organization, array $attributes): DataExport
{
    return app(Tenancy::class)->runFor($organization, fn () => DataExport::create([
        'organization_id' => $organization->id,
        'format' => 'csv',
        'datasets' => ['employees'],
        ...$attributes,
    ]));
}

test('archives past their keep-until date are deleted, in every workspace', function () {
    [$first, $second] = Organization::factory()->count(2)->create();

    Storage::disk('exports')->put('a.zip', 'x');
    Storage::disk('exports')->put('b.zip', 'x');
    Storage::disk('exports')->put('c.zip', 'x');

    $old = exportOf($first, ['status' => DataExport::READY, 'disk' => 'exports', 'path' => 'a.zip', 'expires_at' => now()->subHour()]);
    $alsoOld = exportOf($second, ['status' => DataExport::READY, 'disk' => 'exports', 'path' => 'b.zip', 'expires_at' => now()->subDay()]);
    $fresh = exportOf($first, ['status' => DataExport::READY, 'disk' => 'exports', 'path' => 'c.zip', 'expires_at' => now()->addDay()]);

    $this->artisan('data-export:prune')->assertSuccessful();

    expect(DataExport::withoutGlobalScopes()->find($old->id)->status)->toBe(DataExport::EXPIRED)
        ->and(DataExport::withoutGlobalScopes()->find($alsoOld->id)->status)->toBe(DataExport::EXPIRED)
        ->and(DataExport::withoutGlobalScopes()->find($fresh->id)->status)->toBe(DataExport::READY);

    Storage::disk('exports')->assertMissing('a.zip');
    Storage::disk('exports')->assertMissing('b.zip');
    Storage::disk('exports')->assertExists('c.zip');
});

test('an export that never finished is marked failed', function () {
    $organization = Organization::factory()->create();
    $requester = app(Tenancy::class)->runFor($organization, fn () => User::factory()->create());

    $stuck = exportOf($organization, ['requested_by' => $requester->id, 'status' => DataExport::BUILDING, 'started_at' => now()->subMinutes(DataExport::STALE_AFTER_MINUTES + 1)]);
    $running = exportOf($organization, ['status' => DataExport::BUILDING, 'started_at' => now()->subMinute()]);
    $neverStarted = exportOf($organization, ['status' => DataExport::QUEUED]);
    DataExport::withoutGlobalScopes()->whereKey($neverStarted->id)->update(['created_at' => now()->subMinutes(DataExport::STALE_AFTER_MINUTES + 1)]);

    $this->artisan('data-export:prune')->assertSuccessful();

    expect(DataExport::withoutGlobalScopes()->find($stuck->id)->status)->toBe(DataExport::FAILED)
        ->and(DataExport::withoutGlobalScopes()->find($stuck->id)->error)->not->toBeEmpty()
        ->and(DataExport::withoutGlobalScopes()->find($neverStarted->id)->status)->toBe(DataExport::FAILED)
        ->and(DataExport::withoutGlobalScopes()->find($running->id)->status)->toBe(DataExport::BUILDING)
        ->and(DataExport::withoutGlobalScopes()->find($stuck->id)->completed_at)->not->toBeNull()
        ->and($requester->notifications()->sole()->data['level'])->toBe('error');
});

test('working files a killed build left behind are swept', function () {
    $old = storage_path('app/private/tmp/data-export-killedbuild');
    $fresh = storage_path('app/private/tmp/data-export-stillrunning');
    File::ensureDirectoryExists($old);
    File::ensureDirectoryExists($fresh);
    touch($old, now()->subMinutes(DataExport::STALE_AFTER_MINUTES + 1)->getTimestamp());

    $this->artisan('data-export:prune')->assertSuccessful();

    expect(is_dir($old))->toBeFalse()
        ->and(is_dir($fresh))->toBeTrue();

    File::deleteDirectory($fresh);
});
