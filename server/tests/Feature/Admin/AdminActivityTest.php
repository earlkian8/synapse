<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Support\Facades\Route;

/*
| The admin activity log (ADR 0085): what admins did, append-only, for super
| admins.
*/

it('lists the log newest first and filters it', function () {
    $me = Admin::factory()->superAdmin()->create(['name' => 'Ana Cruz']);
    $ben = Admin::factory()->create(['name' => 'Ben Lim']);

    AdminActivityLog::create(['admin_id' => $ben->id, 'actor_name' => 'Ben Lim', 'actor_email' => $ben->email, 'event' => 'sign_in', 'description' => 'Signed in']);
    AdminActivityLog::create(['actor_email' => 'stranger@example.com', 'event' => 'sign_in_failed', 'description' => 'Sign-in failed: no admin has this email', 'ip_address' => '203.0.113.9']);

    $headers = asPortalAdmin($me);

    $this->getJson('/api/admin/activity', $headers)
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.event', 'sign_in_failed')
        ->assertJsonPath('data.0.event_label', 'Sign-in failed')
        ->assertJsonPath('data.0.actor.email', 'stranger@example.com')
        ->assertJsonPath('stats.failed_sign_ins_7d', 1);

    $this->getJson('/api/admin/activity?event=sign_in', $headers)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor.name', 'Ben Lim');
    $this->getJson("/api/admin/activity?admin_id={$ben->id}", $headers)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/activity?search=203.0.113', $headers)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/activity', $headers)->assertJsonFragment(['value' => 'admin_deleted', 'label' => 'Admin deleted']);
});

it('records who did what with the request it came from', function () {
    $me = Admin::factory()->superAdmin()->create(['name' => 'Ana Cruz']);

    $this->withHeaders(['User-Agent' => 'PortalTest/1.0'])
        ->postJson('/api/admin/admins', ['name' => 'Ben Lim', 'email' => 'ben@synapse.ph', 'role' => 'admin'], asPortalAdmin($me))
        ->assertCreated();

    $log = AdminActivityLog::query()->where('event', 'admin_created')->firstOrFail();

    expect($log->admin_id)->toBe($me->id)
        ->and($log->actor_name)->toBe('Ana Cruz')
        ->and($log->subject_label)->toBe('Ben Lim (ben@synapse.ph)')
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('PortalTest/1.0');
});

it('has no way to change or delete an entry', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/activity'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values()
        ->all();

    expect($writes)->toEqualCanonicalizing(['GET', 'HEAD']);
});
