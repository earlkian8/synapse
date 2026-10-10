<?php

use App\Models\CalendarFeed;
use App\Support\Tenancy;

/*
| The calendar subscription feed (ADR 0070): one secret link per person per
| company, found by its hash, shown again on request, and replaced on reset.
*/

test('a person has one feed per company, its token kept hashed and encrypted', function () {
    $user = actingAsUserWith(['events.respond']);

    $feed = CalendarFeed::issueFor($user);
    $again = CalendarFeed::issueFor($user);
    $token = $feed->plainToken();

    expect($again->id)->toBe($feed->id)
        ->and($again->plainToken())->toBe($token)
        ->and(strlen($token))->toBeGreaterThanOrEqual(40)
        ->and($feed->getRawOriginal('token_hash'))->toBe(hash('sha256', $token))
        ->and($feed->getRawOriginal('token'))->not->toContain($token);
});

test('a token is found without a tenant bound, and a reset one is not', function () {
    $user = actingAsUserWith(['events.respond']);
    $feed = CalendarFeed::issueFor($user);
    $old = $feed->plainToken();

    app(Tenancy::class)->forget();

    expect(CalendarFeed::findByToken($old)?->id)->toBe($feed->id)
        ->and(CalendarFeed::findByToken('not-a-token'))->toBeNull();

    $reset = $feed->reset();

    expect(CalendarFeed::findByToken($old))->toBeNull()
        ->and(CalendarFeed::findByToken($reset->plainToken())?->id)->toBe($feed->id);
});
