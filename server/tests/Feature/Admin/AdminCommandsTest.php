<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;

/*
| The console's way in (ADR 0085): the first admin, and a lost phone.
*/

it('creates an admin from the console with a hidden password', function () {
    $this->artisan('admin:create', ['email' => 'Owner@Synapse.ph', '--name' => 'Platform Owner'])
        ->expectsQuestion('Password (12+ characters, upper and lower case, a number and a symbol)', 'Strong-Enough-42')
        ->expectsQuestion('Password again', 'Strong-Enough-42')
        ->assertSuccessful();

    $admin = Admin::query()->where('email', 'owner@synapse.ph')->firstOrFail();

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->must_change_password)->toBeFalse()
        ->and($admin->hasTwoFactor())->toBeFalse()
        ->and(AdminActivityLog::query()->where('event', 'admin_created')->value('properties'))->toBe(['role' => 'super_admin', 'via' => 'console']);
});

it('refuses a weak or mismatched password at the console', function () {
    $this->artisan('admin:create', ['email' => 'owner@synapse.ph', '--name' => 'Owner'])
        ->expectsQuestion('Password (12+ characters, upper and lower case, a number and a symbol)', 'password')
        ->expectsQuestion('Password again', 'password')
        ->assertFailed();

    $this->artisan('admin:create', ['email' => 'owner@synapse.ph', '--name' => 'Owner'])
        ->expectsQuestion('Password (12+ characters, upper and lower case, a number and a symbol)', 'Strong-Enough-42')
        ->expectsQuestion('Password again', 'Strong-Enough-43')
        ->assertFailed();

    expect(Admin::query()->count())->toBe(0);
});

it('refuses an email another admin has', function () {
    Admin::factory()->create(['email' => 'owner@synapse.ph']);

    $this->artisan('admin:create', ['email' => 'OWNER@synapse.ph', '--name' => 'Owner'])->assertFailed();
});

it('resets an authenticator from the console', function () {
    $admin = Admin::factory()->create(['email' => 'rhea@synapse.ph']);
    portalToken($admin);

    $this->artisan('admin:reset-two-factor', ['email' => 'rhea@synapse.ph', '--force' => true])->assertSuccessful();

    expect($admin->refresh()->hasTwoFactor())->toBeFalse()
        ->and($admin->tokens()->count())->toBe(0);
});
