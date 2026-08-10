<?php

use App\Models\User;
use App\Notifications\Channels\SafeWebPushChannel;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Seed a real in-app (database) notification for a user.
 */
function seedNotification(User $user, array $data = [], bool $read = false): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => SystemNotification::class,
        'data' => array_merge([
            'title' => 'Hello',
            'body' => 'World',
            'url' => null,
            'level' => 'info',
            'category' => 'general',
            'actor' => null,
        ], $data),
        'read_at' => $read ? now() : null,
    ]);
}

// â”€â”€ The centre â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('the notifications page renders', function () {
    $user = actingAsSuperAdmin();
    seedNotification($user);

    $this->get(route('system.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('system/notifications/index')
            ->has('notifications.data', 1)
            ->has('stats')
            ->has('preferences')
            ->has('webPush'));
});

test('a user only sees their own notifications', function () {
    $user = actingAsUserWith([]);
    $other = User::factory()->create();

    seedNotification($user, ['title' => 'Mine']);
    seedNotification($other, ['title' => 'Theirs']);

    $this->get(route('system.notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
});

test('the unread filter only returns unread notifications', function () {
    $user = actingAsUserWith([]);
    seedNotification($user, ['title' => 'Unread one']);
    seedNotification($user, ['title' => 'Read one'], read: true);

    $this->get(route('system.notifications.index', ['filter' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
});

// â”€â”€ Read / delete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('a notification can be marked read', function () {
    $user = actingAsUserWith([]);
    seedNotification($user);
    $id = $user->notifications()->first()->id;

    $this->patch(route('system.notifications.read', $id));

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('all notifications can be marked read', function () {
    $user = actingAsUserWith([]);
    seedNotification($user);
    seedNotification($user);

    $this->post(route('system.notifications.read-all'));

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('a notification can be deleted', function () {
    $user = actingAsUserWith([]);
    seedNotification($user);
    $id = $user->notifications()->first()->id;

    $this->delete(route('system.notifications.destroy', $id));

    expect($user->notifications()->count())->toBe(0);
});

test('the list can be cleared', function () {
    $user = actingAsUserWith([]);
    seedNotification($user);
    seedNotification($user);

    $this->delete(route('system.notifications.clear'));

    expect($user->notifications()->count())->toBe(0);
});

test('a user cannot mark someone elses notification read', function () {
    actingAsUserWith([]);
    $other = User::factory()->create();
    seedNotification($other);
    $id = $other->notifications()->first()->id;

    $this->patch(route('system.notifications.read', $id));

    expect($other->unreadNotifications()->count())->toBe(1);
});

// â”€â”€ Broadcast / compose â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('a user without notifications.send cannot broadcast', function () {
    actingAsUserWith([]);

    $this->post(route('system.notifications.store'), [
        'audience' => 'all',
        'title' => 'Nope',
        'body' => 'Should be blocked',
        'level' => 'info',
    ])->assertForbidden();
});

test('a permitted user can broadcast to everyone', function () {
    Notification::fake();
    actingAsUserWith(['notifications.send']);
    User::factory()->count(3)->create();

    $this->post(route('system.notifications.store'), [
        'audience' => 'all',
        'title' => 'Maintenance tonight',
        'body' => 'The system will be down at 10pm.',
        'level' => 'warning',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTimes(SystemNotification::class, User::where('is_active', true)->count());
});

test('broadcasting to a role only notifies its members', function () {
    Notification::fake();
    actingAsUserWith(['notifications.send']);

    $role = makeRole('announce-target');
    $member = User::factory()->create();
    $member->roles()->attach($role);
    $outsider = User::factory()->create();

    $this->post(route('system.notifications.store'), [
        'audience' => 'role',
        'role_id' => $role->id,
        'title' => 'Team update',
        'body' => 'Read this please.',
        'level' => 'info',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo($member, SystemNotification::class);
    Notification::assertNotSentTo($outsider, SystemNotification::class);
});

test('broadcasting requires a target when audience is a single user', function () {
    actingAsUserWith(['notifications.send']);

    $this->post(route('system.notifications.store'), [
        'audience' => 'user',
        'title' => 'No target',
        'body' => 'Missing user_id.',
        'level' => 'info',
    ])->assertSessionHasErrors('user_id');
});

// â”€â”€ Channels honour preferences â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('the mail channel is skipped when email notifications are off', function () {
    $user = User::factory()->make(['email_notifications' => false, 'push_notifications' => false]);

    expect((new SystemNotification('t', 'b'))->via($user))->toBe(['database']);
});

test('the mail channel is used when email notifications are on', function () {
    $user = User::factory()->make(['email_notifications' => true, 'push_notifications' => false]);

    expect((new SystemNotification('t', 'b'))->via($user))->toBe(['database', 'mail']);
});

test('the web push channel is used only when subscribed and enabled', function () {
    $user = actingAsUserWith([]);
    $user->update(['email_notifications' => false, 'push_notifications' => true]);

    expect((new SystemNotification('t', 'b'))->via($user))->toBe(['database']);

    $user->updatePushSubscription('https://push.example/abc', 'p256dh-key', 'auth-key');

    expect((new SystemNotification('t', 'b'))->via($user->fresh()))
        ->toBe(['database', SafeWebPushChannel::class]);
});

test('every channel is delivered synchronously so no queue worker is required', function () {
    $connections = (new SystemNotification('t', 'b'))->viaConnections();

    expect($connections)->toBe([
        'database' => 'sync',
        'mail' => 'sync',
        SafeWebPushChannel::class => 'sync',
    ]);
});

// â”€â”€ Preferences & subscriptions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('a user can update their notification preferences', function () {
    $user = actingAsUserWith([]);

    $this->put(route('system.notifications.preferences'), ['email' => false, 'push' => false])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email_notifications)->toBeFalse()
        ->and($user->push_notifications)->toBeFalse();
});

test('a user can register and remove a push subscription', function () {
    $user = actingAsUserWith([]);

    $this->post(route('system.notifications.subscriptions.store'), [
        'endpoint' => 'https://push.example/xyz',
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-key',
    ])->assertSessionHasNoErrors();

    expect($user->pushSubscriptions()->count())->toBe(1);

    $this->delete(route('system.notifications.subscriptions.destroy'), [
        'endpoint' => 'https://push.example/xyz',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->pushSubscriptions()->count())->toBe(0);
});

test('the subscriptions route is not swallowed by the notification wildcard', function () {
    // `DELETE …/notifications/subscriptions` used to match the `{notification}`
    // wildcard declared above it, so the controller looked for a notification
    // with the id "subscriptions" — a plain string compared against a uuid
    // column, which aborts the surrounding Postgres transaction and takes the
    // rest of the request down with it. The literal route now wins.
    actingAsUserWith([]);

    $subscriptions = url('/system/notifications/subscriptions');

    expect(route('system.notifications.subscriptions.destroy'))->toBe($subscriptions);

    $this->delete($subscriptions, ['endpoint' => 'https://push.example/none'])
        ->assertSessionHasNoErrors();
});

test('a notification id that is not a uuid is not found rather than fatal', function () {
    // The wildcard is pinned to a UUID, so junk never reaches the query layer.
    actingAsUserWith([]);

    foreach (['not-a-uuid', '../../etc/passwd', '1 OR 1=1'] as $junk) {
        $this->delete(url('/system/notifications/'.urlencode($junk)))->assertNotFound();
        $this->patch(url('/system/notifications/'.urlencode($junk).'/read'))->assertNotFound();
    }
});

test('a notification belonging to another user is left alone', function () {
    $owner = User::factory()->create();
    seedNotification($owner, ['title' => 'Private']);

    $id = $owner->notifications()->first()->id;

    actingAsUserWith([]);

    // Scoped through the caller's own relation, so this is a silent no-op
    // rather than somebody else's notification disappearing.
    $this->delete(route('system.notifications.destroy', $id))->assertSessionHasNoErrors();

    expect($owner->fresh()->notifications()->count())->toBe(1);
});

// â”€â”€ Auto-notification on user creation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('creating a user sends them a welcome notification', function () {
    Notification::fake();
    actingAsUserWith(['users.create']);

    $this->post(route('system.users.store'), [
        'first_name' => 'Fresh',
        'last_name' => 'Hire',
        'email' => 'fresh@example.com',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $created = User::where('email', 'fresh@example.com')->first();

    Notification::assertSentTo($created, SystemNotification::class);
});
