<?php

use App\Models\ProductTourRun;
use App\Models\User;
use App\Support\ProductTour;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0080 — the tour changes (a "What's new" of the stops added since), every
| run is recorded for the per-stop numbers, and the app has a tour of its own.
*/

test('somebody who answered an earlier version is owed a What\'s new, once', function () {
    $user = actingAsSuperAdmin();
    User::withoutTimestamps(fn () => $user->forceFill(['tour_finished_at' => now(), 'tour_outcome' => 'completed', 'tour_version' => 1])->save());

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.tour.owed', true)
        ->where('auth.tour.mode', 'whats_new')
        ->where('auth.tour.seen_version', 1)
        ->where('auth.tour.version', ProductTour::VERSION));

    $this->postJson(route('tour.finish'), ['outcome' => 'skipped'])->assertNoContent();

    $user->refresh();

    // The first answer stands; only the version moves on.
    expect($user->tour_outcome)->toBe('completed')
        ->and($user->tour_version)->toBe(ProductTour::VERSION)
        ->and(ProductTour::owes($user))->toBeFalse();
});

test('a run is filed, moves on, and closes; a replay never answers the tour', function () {
    $user = actingAsSuperAdmin();
    $steps = ['welcome', 'workspace', 'search', 'notifications', 'finish'];

    $id = $this->postJson(route('tour.runs.start'), ['mode' => 'first', 'steps' => $steps])->assertCreated()->json('id');

    $this->patchJson(route('tour.runs.reach', $id), ['index' => 2])->assertNoContent();
    $this->patchJson(route('tour.runs.reach', $id), ['index' => 1])->assertNoContent();

    $run = ProductTourRun::query()->findOrFail($id);
    expect($run->furthest_index)->toBe(2)->and($run->last_step)->toBe('workspace')->and($run->platform)->toBe('web');

    $this->postJson(route('tour.finish'), ['outcome' => 'skipped', 'run' => $id])->assertNoContent();
    expect($run->refresh()->outcome)->toBe('skipped')->and($user->refresh()->tour_outcome)->toBe('skipped');

    $replay = $this->postJson(route('tour.runs.start'), ['mode' => 'replay', 'steps' => $steps])->json('id');
    $this->postJson(route('tour.finish'), ['outcome' => 'completed', 'run' => $replay, 'answers' => false])->assertNoContent();

    expect($user->refresh()->tour_outcome)->toBe('skipped')
        ->and(ProductTourRun::query()->find($replay)->outcome)->toBe('completed')
        ->and(ProductTourRun::query()->find($replay)->furthest_index)->toBe(4);

    $this->postJson(route('tour.runs.start'), ['mode' => 'first', 'steps' => ['<script>']])->assertInvalid('steps.0');
});

test('nobody moves somebody else\'s run', function () {
    actingAsSuperAdmin();
    $id = $this->postJson(route('tour.runs.start'), ['mode' => 'first', 'steps' => ['welcome', 'finish']])->json('id');

    actingAsUserWith(['attendance.clock']);
    $this->patchJson(route('tour.runs.reach', $id), ['index' => 1])->assertNotFound();
});

test('the funnel counts each stop: shown, reached, and where people left', function () {
    testOrganization();
    $people = User::factory()->count(3)->create();
    $steps = ['welcome', 'workspace', 'search', 'finish'];

    foreach ([[3, 'completed'], [1, 'skipped'], [2, null]] as $i => [$furthest, $outcome]) {
        $run = ProductTour::startRun($people[$i], 'web', 'first', $steps);
        ProductTour::reach($run, $furthest);

        if ($outcome !== null) {
            ProductTour::endRun($run, $outcome);
        }
    }

    // The unanswered one was left two hours ago.
    ProductTourRun::query()->whereNull('outcome')->update(['started_at' => now()->subHours(2)]);

    actingAsUserWith(['product-tour.view']);

    $this->get(route('system.product-tour'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('system/product-tour')
        ->where('totals.started', 3)
        ->where('totals.completed', 1)
        ->where('totals.skipped', 1)
        ->where('totals.abandoned', 1)
        ->where('steps.0.id', 'welcome')
        ->where('steps.2.id', 'search')
        ->where('steps.2.reached', 2)
        ->where('steps.1.left_here', 1)
        ->where('steps.2.left_here', 1));
});

test('the funnel needs product-tour.view', function () {
    actingAsUserWith(['users.view']);

    $this->get(route('system.product-tour'))->assertForbidden();
});

test('the app has its own tour, owed once, with its own runs', function () {
    $user = actingAsUserWith(['attendance.clock']);
    Sanctum::actingAs($user);

    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.tour.owed', true);

    $id = $this->postJson('/api/tour/runs', ['mode' => 'first', 'steps' => ['welcome', 'home', 'clock', 'finish']])->assertCreated()->json('id');
    $this->patchJson("/api/tour/runs/{$id}", ['index' => 1])->assertNoContent();
    $this->postJson('/api/tour/finish', ['outcome' => 'completed', 'run' => $id])->assertNoContent();

    expect(ProductTourRun::query()->find($id))->platform->toBe('mobile')->outcome->toBe('completed')
        ->and(ProductTour::owesMobile($user->refresh()))->toBeFalse()
        // The web tour is its own.
        ->and(ProductTour::owes($user))->toBeTrue();

    $this->getJson('/api/me')->assertJsonPath('user.tour.owed', false);
});
