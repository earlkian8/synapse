<?php

use App\Models\Admin;
use App\Models\User;

/*
| The portal's identity stands apart from the ERP's (ADR 0085): neither kind of
| token, nor an ERP session, passes for the other.
*/

it('lets a portal session read who is signed in', function () {
    $admin = Admin::factory()->create(['name' => 'Rhea Santos']);

    $this->getJson('/api/admin/me', asPortalAdmin($admin))
        ->assertOk()
        ->assertJsonPath('admin.name', 'Rhea Santos')
        ->assertJsonPath('admin.two_factor_enabled', true)
        ->assertJsonMissingPath('admin.password')
        ->assertJsonMissingPath('admin.two_factor_secret')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses a request with no session', function () {
    $this->getJson('/api/admin/me')->assertUnauthorized();
});

it('refuses an ERP mobile token on the portal', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->getJson('/api/admin/me', portalHeaders($token))->assertUnauthorized();
    $this->getJson('/api/admin/overview', portalHeaders($token))->assertUnauthorized();
});

it('refuses an ERP web session on the portal', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/api/admin/me', ['Accept' => 'application/json'])->assertUnauthorized();
});

it('refuses a portal token on the mobile API', function () {
    $token = portalToken(Admin::factory()->create());

    $this->getJson('/api/me', portalHeaders($token))->assertUnauthorized();
});

it('refuses a token that lacks the portal ability', function () {
    $admin = Admin::factory()->create();
    $token = $admin->createToken('other', ['something-else'])->plainTextToken;

    $this->getJson('/api/admin/me', portalHeaders($token))->assertUnauthorized();
});

it('ends a deactivated admin\'s session on their next request', function () {
    $admin = Admin::factory()->create();
    $headers = asPortalAdmin($admin);

    $admin->forceFill(['is_active' => false])->save();

    $this->getJson('/api/admin/me', $headers)->assertUnauthorized();
    expect($admin->tokens()->count())->toBe(0);
});

it('keeps plain admins out of the super admin screens', function () {
    $headers = asPortalAdmin(Admin::factory()->create());

    $this->getJson('/api/admin/admins', $headers)->assertForbidden();
    $this->getJson('/api/admin/activity', $headers)->assertForbidden();
    $this->postJson('/api/admin/admins', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'], $headers)->assertForbidden();
    $this->getJson('/api/admin/overview', $headers)->assertOk();
});
