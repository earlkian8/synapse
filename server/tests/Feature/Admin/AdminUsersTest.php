<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Support\Admin\AdminAccounts;
use Illuminate\Validation\ValidationException;

/*
| Admin accounts, managed by super admins (ADR 0085).
*/

it('lists admin accounts with their counts', function () {
    $me = Admin::factory()->superAdmin()->create(['name' => 'Ana Cruz']);
    Admin::factory()->create(['name' => 'Ben Lim']);
    Admin::factory()->inactive()->create(['name' => 'Cora Diaz']);
    Admin::factory()->withoutTwoFactor()->create(['name' => 'Dan Uy']);

    $response = $this->getJson('/api/admin/admins', asPortalAdmin($me))
        ->assertOk()
        ->assertJsonPath('stats.total', 4)
        ->assertJsonPath('stats.active', 3)
        ->assertJsonPath('stats.super_admins', 1)
        ->assertJsonPath('stats.without_two_factor', 1)
        ->assertJsonPath('meta.total', 4);

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Ana Cruz', 'Ben Lim', 'Cora Diaz', 'Dan Uy'])
        ->and($response->json('data.0.is_self'))->toBeTrue();

    $this->getJson('/api/admin/admins?status=inactive', asPortalAdmin($me))->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/admins?search=ben', asPortalAdmin($me))->assertJsonPath('data.0.name', 'Ben Lim');
    $this->getJson('/api/admin/admins?role=super_admin', asPortalAdmin($me))->assertJsonPath('meta.total', 1);
});

it('creates an admin with a one-time password that must be replaced', function () {
    $me = Admin::factory()->superAdmin()->create();

    $response = $this->postJson('/api/admin/admins', [
        'name' => 'Rhea Santos',
        'email' => ' Rhea@Synapse.PH ',
        'role' => 'admin',
    ], asPortalAdmin($me))->assertCreated();

    $admin = Admin::query()->where('email', 'rhea@synapse.ph')->firstOrFail();
    $password = $response->json('temporary_password');

    expect($password)->toHaveLength(24)
        ->and($admin->must_change_password)->toBeTrue()
        ->and($admin->hasTwoFactor())->toBeFalse()
        ->and($admin->created_by)->toBe($me->id);

    app('auth')->forgetGuards();
    $this->postJson('/api/admin/auth/login', ['email' => 'rhea@synapse.ph', 'password' => $password])
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_setup');

    expect(AdminActivityLog::query()->where('event', 'admin_created')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

it('keeps admin emails unique whatever their case', function () {
    $me = Admin::factory()->superAdmin()->create();
    Admin::factory()->create(['email' => 'rhea@synapse.ph']);

    $this->postJson('/api/admin/admins', ['name' => 'Rhea', 'email' => 'RHEA@synapse.ph', 'role' => 'admin'], asPortalAdmin($me))
        ->assertJsonValidationErrors('email');
});

it('edits an admin and records what changed', function () {
    $me = Admin::factory()->superAdmin()->create();
    $admin = Admin::factory()->create(['name' => 'Ben Lim']);

    $this->patchJson("/api/admin/admins/{$admin->id}", [
        'name' => 'Benjamin Lim',
        'email' => $admin->email,
        'role' => 'super_admin',
    ], asPortalAdmin($me))->assertOk()->assertJsonPath('admin.role', 'super_admin');

    $log = AdminActivityLog::query()->where('event', 'admin_updated')->firstOrFail();
    expect($log->properties['changes'])->toHaveKeys(['name', 'role'])
        ->and($log->properties['changes']['name'])->toBe(['from' => 'Ben Lim', 'to' => 'Benjamin Lim']);
});

it('does not let an admin act on their own account here', function () {
    $me = Admin::factory()->superAdmin()->create();
    Admin::factory()->superAdmin()->create();
    $headers = asPortalAdmin($me);

    $this->patchJson("/api/admin/admins/{$me->id}", ['name' => $me->name, 'email' => $me->email, 'role' => 'admin'], $headers)->assertJsonValidationErrors('admin');
    $this->patchJson("/api/admin/admins/{$me->id}/status", ['is_active' => false], $headers)->assertJsonValidationErrors('admin');
    $this->postJson("/api/admin/admins/{$me->id}/reset-password", [], $headers)->assertJsonValidationErrors('admin');
    $this->postJson("/api/admin/admins/{$me->id}/reset-two-factor", [], $headers)->assertJsonValidationErrors('admin');
    $this->deleteJson("/api/admin/admins/{$me->id}", [], $headers)->assertJsonValidationErrors('admin');

    // A name change is not an act on one's access.
    $this->patchJson("/api/admin/admins/{$me->id}", ['name' => 'New Name', 'email' => $me->email, 'role' => 'super_admin'], $headers)->assertOk();
});

// Through the API the caller is itself an active super admin, so the guard
// below only bites on a race; it is exercised on the class directly.
it('refuses to demote the last active super admin', function () {
    $only = Admin::factory()->superAdmin()->create();
    $actor = Admin::factory()->superAdmin()->inactive()->create();

    expect(fn () => app(AdminAccounts::class)->update($only, ['name' => $only->name, 'email' => $only->email, 'role' => 'admin'], $actor))
        ->toThrow(ValidationException::class, 'This is the only active super admin.');

    expect($only->refresh()->role)->toBe('super_admin');
});

it('refuses to deactivate the last active super admin', function () {
    $only = Admin::factory()->superAdmin()->create();

    expect(fn () => app(AdminAccounts::class)->setActive($only, false, Admin::factory()->superAdmin()->inactive()->create()))
        ->toThrow(ValidationException::class, 'This is the only active super admin.');

    expect($only->refresh()->is_active)->toBeTrue();
});

it('lets one of two super admins deactivate the other, and signs them out', function () {
    $me = Admin::factory()->superAdmin()->create();
    $other = Admin::factory()->superAdmin()->create();
    portalToken($other);

    $this->patchJson("/api/admin/admins/{$other->id}/status", ['is_active' => false], asPortalAdmin($me))
        ->assertOk()
        ->assertJsonPath('admin.is_active', false);

    expect($other->tokens()->count())->toBe(0)
        ->and(AdminActivityLog::query()->where('event', 'admin_deactivated')->exists())->toBeTrue();

    $this->patchJson("/api/admin/admins/{$other->id}/status", ['is_active' => true], asPortalAdmin($me))
        ->assertJsonPath('admin.is_active', true);
});

it('resets a password and ends the admin\'s sessions', function () {
    $me = Admin::factory()->superAdmin()->create();
    $admin = Admin::factory()->create();
    $session = portalToken($admin);

    $response = $this->postJson("/api/admin/admins/{$admin->id}/reset-password", [], asPortalAdmin($me))
        ->assertOk()
        ->assertJsonPath('admin.must_change_password', true);

    expect($response->json('temporary_password'))->toHaveLength(24);
    $this->getJson('/api/admin/me', portalHeaders($session))->assertUnauthorized();
});

it('resets an authenticator so the next sign-in sets one up', function () {
    $me = Admin::factory()->superAdmin()->create();
    $admin = Admin::factory()->create();
    $session = portalToken($admin);

    $this->postJson("/api/admin/admins/{$admin->id}/reset-two-factor", [], asPortalAdmin($me))
        ->assertOk()
        ->assertJsonPath('admin.two_factor_enabled', false);

    $this->getJson('/api/admin/me', portalHeaders($session))->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'Correct-Horse-9'])
        ->assertJsonPath('status', 'two_factor_setup');
});

it('deletes only a deactivated admin, and keeps their trail', function () {
    $me = Admin::factory()->superAdmin()->create();
    $admin = Admin::factory()->create(['name' => 'Ben Lim']);
    $headers = asPortalAdmin($me);

    $this->deleteJson("/api/admin/admins/{$admin->id}", [], $headers)->assertJsonValidationErrors('admin');

    $this->patchJson("/api/admin/admins/{$admin->id}/status", ['is_active' => false], asPortalAdmin($me))->assertOk();
    $this->deleteJson("/api/admin/admins/{$admin->id}", [], asPortalAdmin($me))->assertOk();

    expect(Admin::query()->find($admin->id))->toBeNull()
        ->and(AdminActivityLog::query()->where('event', 'admin_deleted')->value('subject_label'))->toContain('Ben Lim');
});
