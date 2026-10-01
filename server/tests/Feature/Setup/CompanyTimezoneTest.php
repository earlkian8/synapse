<?php

use App\Support\MobileSession;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The clock an organisation keeps (ADR 0036): set on the Company Profile and in
| the setup wizard, and shared with every screen that shows a time.
*/

test('the company profile keeps a time zone from the list', function () {
    actingAsSuperAdmin();
    $organization = testOrganization();

    $this->post(route('setup.company.update'), ['name' => 'Acme', 'timezone' => 'Mars/Olympus_Mons'])
        ->assertSessionHasErrors('timezone');

    $this->post(route('setup.company.update'), ['name' => 'Acme'])
        ->assertSessionHasErrors('timezone');

    $this->post(route('setup.company.update'), ['name' => 'Acme', 'timezone' => 'Europe/London'])
        ->assertSessionHasNoErrors();

    expect($organization->fresh()->timezone)->toBe('Europe/London');
});

test('the company profile offers every zone with its offset', function () {
    actingAsSuperAdmin();

    $this->get(route('setup.company.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.timezone', 'Asia/Manila')
            ->where('timezones', fn ($zones) => collect($zones)->contains(
                fn (array $zone): bool => $zone['value'] === 'Asia/Manila' && $zone['offset'] === 'UTC+08:00',
            )));
});

test('the organisation clock is shared with the web app and the mobile session', function () {
    $user = actingAsSuperAdmin();
    $organization = testOrganization();
    $organization->forceFill(['timezone' => 'America/New_York'])->save();

    $this->get(route('attendance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.organization.timezone', 'America/New_York'));

    expect(app(MobileSession::class)->payload($user, $organization)['organization']['timezone'])->toBe('America/New_York');
});
