<?php

use App\Models\ActivityLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    actingAsSuperAdmin();
});

/**
 * Create an activity log row for assertions.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeLog(array $attributes = []): ActivityLog
{
    return ActivityLog::create(array_merge([
        'log_name' => 'user_management',
        'event' => 'created',
        'description' => 'Created user',
        'causer_id' => null,
        'subject_label' => 'Someone',
    ], $attributes));
}

// ── Listing ─────────────────────────────────────────────────────────────────

test('the activity log index renders', function () {
    makeLog();

    $this->get(route('system.activity-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/activity-logs/index')
            ->has('logs.data', 1)
            ->has('stats')
            ->has('filters'));
});

test('it filters logs by event', function () {
    makeLog(['event' => 'created']);
    makeLog(['event' => 'deleted']);

    $this->get(route('system.activity-logs.index', ['event' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
});

test('it searches logs', function () {
    makeLog(['description' => 'Created user Zoltan']);
    makeLog(['description' => 'Created user Maria']);

    $this->get(route('system.activity-logs.index', ['search' => 'Zoltan']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
});

// ── Mutations ───────────────────────────────────────────────────────────────

test('it deletes a single log entry', function () {
    $log = makeLog();

    $this->delete(route('system.activity-logs.destroy', $log))->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('activity_logs', ['id' => $log->id]);
});

test('it bulk deletes log entries', function () {
    $logs = collect([makeLog(), makeLog(), makeLog()]);

    $this->post(route('system.activity-logs.bulk'), [
        'action' => 'delete',
        'ids' => $logs->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    expect(ActivityLog::count())->toBe(0);
});

test('it clears the entire activity log', function () {
    makeLog();
    makeLog();

    $this->delete(route('system.activity-logs.clear'))->assertSessionHasNoErrors();

    expect(ActivityLog::count())->toBe(0);
});

test('it exports logs as a csv download', function () {
    makeLog(['description' => 'Exportable entry']);

    $response = $this->get(route('system.activity-logs.export'));

    $response->assertOk();
    $response->assertDownload();
    expect($response->streamedContent())
        ->toContain('Event')
        ->toContain('Exportable entry');
});

// ── Logging integration with User Management ────────────────────────────────

test('creating a user writes an activity log', function () {
    $this->post(route('system.users.store'), [
        'first_name' => 'Logged',
        'last_name' => 'User',
        'email' => 'logged@example.com',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(ActivityLog::where('event', 'created')->where('log_name', 'user_management')->exists())
        ->toBeTrue();
});

test('archiving a user writes an activity log', function () {
    $user = User::factory()->create();

    $this->delete(route('system.users.destroy', $user))->assertSessionHasNoErrors();

    $log = ActivityLog::where('event', 'archived')->first();

    expect($log)->not->toBeNull()
        ->and($log->subject_id)->toBe($user->id);
});

test('resetting a password writes an activity log', function () {
    $user = User::factory()->create();

    $this->put(route('system.users.password', $user), [
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])->assertSessionHasNoErrors();

    expect(ActivityLog::where('event', 'password_reset')->exists())->toBeTrue();
});

test('a bulk user action writes a single summary log', function () {
    $users = User::factory()->count(3)->create();

    $this->post(route('system.users.bulk'), [
        'action' => 'deactivate',
        'ids' => $users->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    expect(ActivityLog::where('event', 'deactivated')->count())->toBe(1);
});
