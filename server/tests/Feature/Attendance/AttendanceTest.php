<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;

// ── Board ─────────────────────────────────────────────────────────────────────

test('the attendance board renders', function () {
    actingAsSuperAdmin();
    Employee::factory()->count(3)->create();

    $this->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/index')
            ->has('stats')
            ->has('options')
            ->has('can'));
});

// ── Export ────────────────────────────────────────────────────────────────────

test('it exports the daily log as csv', function () {
    actingAsSuperAdmin();
    Employee::factory()->count(2)->create();

    $response = $this->get(route('attendance.export', ['tab' => 'today']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('it exports the monthly summary as csv', function () {
    actingAsSuperAdmin();
    Employee::factory()->create();

    $response = $this->get(route('attendance.export', ['tab' => 'monthly']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

// ── Bulk approve ──────────────────────────────────────────────────────────────

test('it bulk-approves every pending record', function () {
    $user = actingAsSuperAdmin();
    AttendanceRecord::factory()->count(3)->create(['approval_status' => 'pending']);
    AttendanceRecord::factory()->create(['approval_status' => 'approved']);

    $this->patch(route('attendance.approve-all'))->assertSessionHasNoErrors();

    expect(AttendanceRecord::where('approval_status', 'pending')->count())->toBe(0)
        ->and(AttendanceRecord::where('approval_status', 'approved')->count())->toBe(4)
        ->and(AttendanceRecord::whereNotNull('approved_by')->where('approved_by', $user->id)->count())->toBe(3);
});

// ── Authorization ─────────────────────────────────────────────────────────────

test('exporting requires the view permission', function () {
    actingAsUserWith([]);

    $this->get(route('attendance.export'))->assertForbidden();
});

test('bulk approve requires the manage permission', function () {
    actingAsUserWith(['attendance.view']);

    $this->patch(route('attendance.approve-all'))->assertForbidden();
});
