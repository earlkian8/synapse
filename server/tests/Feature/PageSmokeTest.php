<?php

use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every page in the app renders.
 *
 * The per-module tests assert what a page *says*; this asserts that it comes
 * back at all. A page breaks for reasons no module test is looking at — a prop
 * a controller stopped sending, a resource reading a column a migration
 * dropped, a component removed from under an import — and those failures are
 * invisible until somebody clicks. Walking every route as a fully-permitted
 * user is the cheapest possible guard against that whole class.
 *
 * Kept deliberately dumb: name in, 200 out, and the Inertia component the route
 * is supposed to render. Nothing here should need updating except when a page is
 * added or removed.
 */

/** Every page route, and the Inertia component it must render. */
const PAGES = [
    'dashboard' => 'dashboard',

    // Workforce
    'employees.index' => 'employees/index',
    'employees.access' => 'employees/access',
    'attendance.index' => 'attendance/index',
    'leave.index' => 'leave/index',
    'leave.balances.index' => 'leave/balances',
    'performance.index' => 'performance/index',
    'training.index' => 'training/index',
    'awards.index' => 'awards/index',
    'awards.nominations' => 'awards/nominations',
    'events.index' => 'events/index',
    'onboarding.index' => 'onboarding/index',
    'offboarding.index' => 'offboarding/index',
    'recruitment.index' => 'recruitment/index',
    'reports.index' => 'reports/index',

    // Analytics. The ML service is not running in tests, so these also prove the
    // graceful-degradation path renders rather than throwing.
    'analytics.promotion-readiness.index' => 'analytics/promotion-readiness',
    'analytics.performance-forecast.index' => 'analytics/performance-forecast',
    'analytics.attrition.index' => 'analytics/attrition',

    // Company Setup
    'setup.company.edit' => 'setup/company',
    'setup.departments.index' => 'setup/departments',
    'setup.leave-types.index' => 'setup/leave-types',
    'setup.kpi.index' => 'setup/kpi',
    'setup.award-types.index' => 'setup/award-types',
    'setup.schedule.index' => 'setup/schedule',
    'setup.onboarding.index' => 'setup/onboarding',
    'setup.offboarding.index' => 'setup/offboarding',

    // System
    'system.users.index' => 'system/users/index',
    'system.roles.index' => 'system/roles/index',
    'system.activity-logs.index' => 'system/activity-logs/index',
    'system.notifications.index' => 'system/notifications/index',
    'system.trash.index' => 'system/trash/index',

    // Account
    'profile.edit' => 'settings/profile',
    'appearance.edit' => 'settings/appearance',
];

/**
 * Pages that belong to the signed-in person rather than to a permission — the
 * account settings, their own notification preferences, their own assistant
 * history, and the reports hub (which re-authorises each report individually,
 * so the catalogue itself is open and simply shows less).
 */
const UNGATED = [
    'dashboard',
    'profile.edit',
    'appearance.edit',
    'reports.index',
    'system.notifications.index',
];

/** Every CSV / file download, which renders nothing but must still stream. */
const DOWNLOADS = [
    'employees.export',
    'attendance.export',
    'performance.export',
    'training.export',
    'awards.export',
    'events.export',
    'offboarding.export',
    'recruitment.export',
    'system.activity-logs.export',
    'system.roles.export',
    'system.users.export',
    'system.users.import.template',
];

test('every page renders for a fully-permitted user', function (string $name, string $component) {
    actingAsSuperAdmin();

    $this->get(route($name))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with(collect(PAGES)->map(fn (string $component, string $name): array => [$name, $component])->values()->all());

test('every download streams', function (string $name) {
    actingAsSuperAdmin();

    $this->get(route($name))->assertOk();
})->with(DOWNLOADS);

test('a page needing a permission is closed to a user without it', function (string $name) {
    actingAsUserWith([]);

    $expected = in_array($name, UNGATED, true) ? 200 : 403;

    expect($this->get(route($name))->status())->toBe($expected);
})->with(array_keys(PAGES));

// ── The pages with a condition attached ──────────────────────────────────────

test('the self-service DTR renders once the account is linked to a roster line', function () {
    $user = actingAsSuperAdmin();

    // Self-scoped, so it answers 403 until the person actually has a roster line —
    // holding every permission in the catalogue does not stand in for having one.
    $this->get(route('attendance.me'))->assertForbidden();

    Employee::factory()->create(['user_id' => $user->id]);

    $this->get(route('attendance.me'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance/me'));
});

test('the security page sits behind a password confirmation', function () {
    actingAsSuperAdmin();

    // RequirePassword bounces to the confirmation screen rather than rendering.
    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
});

test('the assistant conversation list answers as JSON, not as a page', function () {
    actingAsSuperAdmin();

    $this->getJson(route('assistant.conversations.index'))
        ->assertOk()
        ->assertJsonStructure(['conversations']);
});
