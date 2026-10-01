<?php

use App\Models\User;
use App\Support\ProductTour;
use App\Support\SystemGuide;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

// The first-run tour (ADR 0060). The steps live in the browser; the server
// keeps whether the tour is still owed, and the first answer given to it.

test('somebody new is owed the tour, and the app shell is told so', function () {
    actingAsUserWith(['attendance.clock', 'leave.request']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.tour.owed', true));
});

test('tour state reaches the browser once, as auth.tour, not on the user', function () {
    actingAsSuperAdmin();

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.tour')
            ->missing('auth.user.tour_finished_at')
            ->missing('auth.user.tour_outcome'));
});

test('finishing the tour records it and stops it being offered', function () {
    $user = actingAsSuperAdmin();

    $this->postJson(route('tour.finish'), ['outcome' => ProductTour::COMPLETED])
        ->assertNoContent();

    $user->refresh();

    expect($user->tour_finished_at)->not->toBeNull()
        ->and($user->tour_outcome)->toBe(ProductTour::COMPLETED);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.tour.owed', false));
});

test('skipping the tour is an answer too', function () {
    $user = actingAsUserWith(['attendance.clock']);

    $this->postJson(route('tour.finish'), ['outcome' => ProductTour::SKIPPED])
        ->assertNoContent();

    expect($user->refresh()->tour_outcome)->toBe(ProductTour::SKIPPED)
        ->and(ProductTour::owes($user))->toBeFalse();
});

test('the first answer stands — a replay or a double submit does not rewrite it', function () {
    $user = actingAsSuperAdmin();

    $this->travelTo(now()->subDay(), function () {
        $this->postJson(route('tour.finish'), ['outcome' => ProductTour::COMPLETED])->assertNoContent();
    });

    $first = $user->refresh()->tour_finished_at;

    $this->postJson(route('tour.finish'), ['outcome' => ProductTour::SKIPPED])->assertNoContent();

    $user->refresh();

    expect($user->tour_outcome)->toBe(ProductTour::COMPLETED)
        ->and($user->tour_finished_at->equalTo($first))->toBeTrue();
});

test('finishing the tour is not an edit to the account', function () {
    $user = actingAsSuperAdmin();
    $updated = $user->updated_at;

    $this->travel(5)->minutes();

    $this->postJson(route('tour.finish'), ['outcome' => ProductTour::COMPLETED])->assertNoContent();

    expect($user->refresh()->updated_at->equalTo($updated))->toBeTrue();
});

test('an outcome other than completed or skipped is refused', function (mixed $outcome) {
    $user = actingAsSuperAdmin();

    // A web route: a refusal comes back as session errors (JSON is api/* only).
    $this->post(route('tour.finish'), ['outcome' => $outcome])
        ->assertSessionHasErrors('outcome');

    expect(ProductTour::owes($user->refresh()))->toBeTrue();
})->with([
    'unknown' => 'maybe',
    'missing' => null,
    'not a string' => [['completed']],
]);

test('only somebody signed in can answer the tour', function () {
    $this->post(route('tour.finish'), ['outcome' => ProductTour::COMPLETED])
        ->assertRedirect(route('login'));
});

test('a new registration leaves its owner owing the tour', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'organization_name' => 'Tour Company',
        'first_name' => 'Tess',
        'last_name' => 'Tour',
        'email' => 'tess@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(ProductTour::owes(User::where('email', 'tess@example.com')->firstOrFail()))->toBeTrue();
});

test('the migration back-fills every existing account as finished, with no outcome', function () {
    $this->freezeTime();

    $migration = require database_path('migrations/2026_09_29_000000_add_product_tour_to_users.php');

    $migration->down();

    $existing = DB::table('users')->insertGetId([
        'first_name' => 'Early',
        'last_name' => 'Adopter',
        'email' => 'early@example.com',
        'is_active' => true,
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);

    $migration->up();

    $row = DB::table('users')->where('id', $existing)->first();

    expect($row->tour_finished_at)->not->toBeNull()
        ->and($row->tour_outcome)->toBeNull()
        ->and((string) $row->updated_at)->toBe(now()->subYear()->toDateTimeString());
});

test("the assistant's guide knows where the tour is replayed from", function () {
    $user = actingAsUserWith(['attendance.clock']);

    expect(SystemGuide::search($user, 'how do I replay the tutorial?')->keys()->first())->toBe('tour');
});
