<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

// ── Listing ─────────────────────────────────────────────────────────────────

test('the index page renders with users, stats and filters', function () {
    User::factory()->count(3)->create();

    $this->get(route('system.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/users/index')
            ->has('users.data')
            ->has('users.meta')
            ->has('stats')
            ->has('filters'));
});

test('guests cannot access user management', function () {
    auth()->logout();

    $this->get(route('system.users.index'))->assertRedirect(route('login'));
});

test('it filters users by search term', function () {
    User::factory()->create(['first_name' => 'Zoltan', 'email' => 'zoltan@example.com']);
    User::factory()->count(2)->create();

    $this->get(route('system.users.index', ['search' => 'Zoltan']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

test('it filters users by inactive status', function () {
    User::factory()->create(['is_active' => false]);
    User::factory()->count(2)->create(['is_active' => true]);

    $this->get(route('system.users.index', ['status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

test('the archived filter only lists soft-deleted users', function () {
    User::factory()->create()->delete();
    User::factory()->count(2)->create();

    $this->get(route('system.users.index', ['status' => 'archived']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

test('it sorts results by first name', function () {
    User::factory()->create(['first_name' => 'Aaron', 'email' => 'a@sortme.test']);
    User::factory()->create(['first_name' => 'Mona', 'email' => 'm@sortme.test']);
    User::factory()->create(['first_name' => 'Zane', 'email' => 'z@sortme.test']);

    $this->get(route('system.users.index', [
        'search' => 'sortme.test',
        'sort' => 'first_name',
        'direction' => 'asc',
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('users.data', 3)
        ->where('users.data.0.first_name', 'Aaron'));
});

// ── Create ──────────────────────────────────────────────────────────────────

test('it creates a user with a password', function () {
    $this->post(route('system.users.store'), [
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => 'new.person@example.com',
        'is_active' => true,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'new.person@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(Hash::check('Password123!', $user->password))->toBeTrue();
});

test('it creates an invited user without a password', function () {
    $this->post(route('system.users.store'), [
        'first_name' => 'No',
        'last_name' => 'Pass',
        'email' => 'no.pass@example.com',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'no.pass@example.com')->first()->password)->toBeNull();
});

test('it rejects a duplicate email on create', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('system.users.store'), [
        'first_name' => 'Dup',
        'last_name' => 'User',
        'email' => 'taken@example.com',
        'is_active' => true,
    ])->assertSessionHasErrors('email');
});

// ── Update ──────────────────────────────────────────────────────────────────

test('it updates a user', function () {
    $user = User::factory()->create();

    $this->patch(route('system.users.update', $user), [
        'first_name' => 'Updated',
        'last_name' => $user->last_name,
        'email' => $user->email,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->first_name)->toBe('Updated');
});

test('changing the email resets verification', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->patch(route('system.users.update', $user), [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => 'changed@example.com',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

// ── Status ──────────────────────────────────────────────────────────────────

test('it deactivates and reactivates a user', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->patch(route('system.users.status', $user), ['is_active' => false])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->is_active)->toBeFalse();

    $this->patch(route('system.users.status', $user), ['is_active' => true]);
    expect($user->fresh()->is_active)->toBeTrue();
});

test('an admin cannot deactivate themselves', function () {
    $this->patch(route('system.users.status', $this->admin), ['is_active' => false]);

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

// ── Password reset ──────────────────────────────────────────────────────────

test('it resets a user password', function () {
    $user = User::factory()->create();

    $this->put(route('system.users.password', $user), [
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password_changed_at)->not->toBeNull();
});

// ── Archive / restore / delete ──────────────────────────────────────────────

test('it archives a user', function () {
    $user = User::factory()->create();

    $this->delete(route('system.users.destroy', $user))->assertSessionHasNoErrors();

    $this->assertSoftDeleted($user);
});

test('an admin cannot archive themselves', function () {
    $this->delete(route('system.users.destroy', $this->admin));

    expect($this->admin->fresh()->trashed())->toBeFalse();
});

test('it restores an archived user', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->patch(route('system.users.restore', $user->id))->assertSessionHasNoErrors();

    expect(User::find($user->id))->not->toBeNull();
});

test('it permanently deletes a user', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->delete(route('system.users.force-delete', $user->id))->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('an admin cannot permanently delete themselves', function () {
    $this->delete(route('system.users.force-delete', $this->admin->id));

    expect(User::find($this->admin->id))->not->toBeNull();
});

// ── Bulk actions ────────────────────────────────────────────────────────────

test('it bulk archives users', function () {
    $users = User::factory()->count(3)->create();

    $this->post(route('system.users.bulk'), [
        'action' => 'archive',
        'ids' => $users->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $users->each(fn (User $user) => $this->assertSoftDeleted($user));
});

test('bulk actions exclude the acting admin', function () {
    $other = User::factory()->create(['is_active' => true]);

    $this->post(route('system.users.bulk'), [
        'action' => 'deactivate',
        'ids' => [$this->admin->id, $other->id],
    ])->assertSessionHasNoErrors();

    expect($this->admin->fresh()->is_active)->toBeTrue()
        ->and($other->fresh()->is_active)->toBeFalse();
});

test('it rejects an unknown bulk action', function () {
    $user = User::factory()->create();

    $this->post(route('system.users.bulk'), [
        'action' => 'explode',
        'ids' => [$user->id],
    ])->assertSessionHasErrors('action');
});

// ── Export ──────────────────────────────────────────────────────────────────

test('it exports filtered users as a csv download', function () {
    User::factory()->create(['email' => 'export.me@example.com']);

    $response = $this->get(route('system.users.export'));

    $response->assertOk();
    $response->assertDownload();
    expect($response->streamedContent())
        ->toContain('Employee ID')
        ->toContain('export.me@example.com');
});
