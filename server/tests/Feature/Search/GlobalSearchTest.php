<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Support\Search\GlobalSearch;
use App\Support\Tenancy;

/*
| Global search (ADR 0069): the ⌘K palette's one endpoint. It is read for the
| person searching — a kind they cannot open is never queried — and it never
| leaves the company that is bound.
*/

/** An employee with exactly this name, in the bound company. */
function gsEmployee(string $first, string $last, array $attributes = []): Employee
{
    testOrganization();

    return Employee::factory()->create([
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
        'suffix' => null,
        ...$attributes,
    ]);
}

/**
 * The groups a search answered, keyed by kind.
 *
 * @return array<string, array<string, mixed>>
 */
function gsGroups(string $query): array
{
    return collect(test()->getJson('/search?q='.urlencode($query))->assertOk()->json('groups'))
        ->keyBy('key')
        ->all();
}

test('search needs somebody signed in', function () {
    $this->getJson('/search?q=maria')->assertUnauthorized();
});

test('the query must be between 2 and 80 characters', function (?string $query) {
    actingAsUserWith(['employees.view']);

    $this->getJson('/search'.($query === null ? '' : '?q='.urlencode($query)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
})->with([
    'missing' => [null],
    'one character' => ['a'],
    'too long' => [str_repeat('a', 81)],
]);

test('employees are found by every word typed', function () {
    actingAsUserWith(['employees.view']);
    $maria = gsEmployee('Maria', 'Santos');
    gsEmployee('Maria', 'Reyes');

    $items = gsGroups('maria santos')['employees']['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Maria Santos')
        ->and($items[0]['hint'])->toBe($maria->employee_no)
        ->and($items[0]['href'])->toBe('/employees?search='.urlencode($maria->employee_no).'&open='.$maria->id);
});

test('an exact name ranks above one that only contains the words', function () {
    actingAsUserWith(['employees.view']);
    gsEmployee('Mariana', 'Abad');
    gsEmployee('Ana', 'Cruz');

    $titles = collect(gsGroups('ana')['employees']['items'])->pluck('title');

    expect($titles->first())->toBe('Ana Cruz')
        ->and($titles)->toContain('Mariana Abad');
});

test('employees are not searched for someone who cannot view them', function () {
    actingAsUserWith([]);
    gsEmployee('Maria', 'Santos');

    expect(gsGroups('maria'))->not->toHaveKey('employees');
});

test('search never reaches into another company', function () {
    actingAsUserWith(['employees.view']);
    $home = testOrganization();

    app(Tenancy::class)->runFor(
        Organization::factory()->create(),
        fn () => Employee::factory()->create(['first_name' => 'Quintessa', 'last_name' => 'Elsewhere']),
    );
    app(Tenancy::class)->set($home);

    expect(gsGroups('quintessa'))->not->toHaveKey('employees');
});

test('archived employees are left out', function () {
    actingAsUserWith(['employees.view']);
    gsEmployee('Maria', 'Santos')->delete();

    expect(gsGroups('maria'))->not->toHaveKey('employees');
});

test('nothing is answered when no company is bound', function () {
    $user = actingAsSuperAdmin();
    gsEmployee('Maria', 'Santos');

    app(Tenancy::class)->forget();

    expect(app(GlobalSearch::class)->search($user, 'maria'))->toBe([]);
});

test('wildcard characters are searched as text', function () {
    actingAsUserWith(['employees.view']);
    gsEmployee('Maria', 'Santos');

    expect(gsGroups('%%'))->not->toHaveKey('employees');
});

test('a screen is found by what it is for', function () {
    actingAsUserWith(['setup.schedule.view']);

    $screens = gsGroups('holidays')['screens'];

    expect($screens['label'])->toBe('Screens')
        ->and(collect($screens['items'])->pluck('href'))->toContain('/setup/schedule')
        ->and($screens['items'][0]['subtitle'])->toBe('Company Setup');
});

test('a screen somebody cannot open is never offered', function () {
    actingAsUserWith([]);

    $hrefs = collect(gsGroups('holidays')['screens']['items'] ?? [])->pluck('href');

    expect($hrefs)->not->toContain('/setup/schedule');
});

test('help articles are found, after every record', function () {
    actingAsUserWith(['leave.view']);
    gsEmployee('Leaven', 'Cruz');

    $groups = gsGroups('leave');

    expect($groups)->toHaveKey('help')
        ->and(collect($groups['help']['items'])->pluck('href')->every(fn (string $href) => str_starts_with($href, '/help/')))->toBeTrue()
        ->and(array_key_last($groups))->toBe('help')
        ->and(array_key_first($groups))->toBe('screens');
});

test('somebody with no record permissions still finds screens and help', function () {
    actingAsUserWith([]);

    expect(array_keys(gsGroups('notifications')))->toBe(['screens', 'help']);
});
