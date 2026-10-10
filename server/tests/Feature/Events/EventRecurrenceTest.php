<?php

use App\Models\Employee;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use App\Support\OrganizationClock;
use Illuminate\Support\Facades\Notification;

/*
| Repeating events (ADR 0070): a rule makes ordinary events, one per date, on the
| office clock; an occurrence is edited, archived or staffed alone or with every
| later one.
*/

beforeEach(function () {
    $this->organizer = actingAsSuperAdmin();
    testOrganization()->update(['timezone' => 'Asia/Manila']);
    $this->workflow = app(EventWorkflow::class);
});

/** Each occurrence of the series, as "Y-m-d H:i" on the office clock. */
function seriesLocalStarts(Event $first): array
{
    return Event::withTrashed()->where('series_id', $first->series_id)->orderBy('starts_at')->get()
        ->map(fn (Event $event): string => OrganizationClock::local($event->starts_at)->format('Y-m-d H:i'))
        ->all();
}

function standup(EventWorkflow $workflow, User $organizer, array $repeat, array $data = []): Event
{
    return $workflow->schedule([
        'title' => 'Standup',
        'type' => 'meeting',
        'starts_at' => '2030-03-04T09:00',
        'ends_at' => '2030-03-04T09:15',
        'repeat' => $repeat,
        ...$data,
    ], $organizer);
}

test('a weekly series on Monday and Wednesday makes every date, on the office clock', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'weekly', 'interval' => 1, 'weekdays' => [1, 3], 'count' => 6]);

    expect(seriesLocalStarts($first))->toBe([
        '2030-03-04 09:00', '2030-03-06 09:00',
        '2030-03-11 09:00', '2030-03-13 09:00',
        '2030-03-18 09:00', '2030-03-20 09:00',
    ])
        ->and($first->starts_at->utc()->format('H:i'))->toBe('01:00')
        ->and(Event::query()->where('series_id', $first->series_id)->get()->every(
            fn (Event $event): bool => (int) $event->starts_at->diffInMinutes($event->ends_at) === 15,
        ))->toBeTrue()
        ->and(EventSeries::query()->sole()->summary())->toBe('Every week on Mon, Wed');
});

test('a series every other week skips the weeks between', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'weekly', 'interval' => 2, 'until' => '2030-04-01']);

    expect(seriesLocalStarts($first))->toBe(['2030-03-04 09:00', '2030-03-18 09:00', '2030-04-01 09:00']);
});

test('a monthly series from the 31st keeps to the end of shorter months', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'monthly', 'count' => 4], [
        'starts_at' => '2031-01-31T09:00',
        'ends_at' => '2031-01-31T10:00',
    ]);

    expect(seriesLocalStarts($first))->toBe([
        '2031-01-31 09:00', '2031-02-28 09:00', '2031-03-31 09:00', '2031-04-30 09:00',
    ]);
});

test('a series of more than a hundred dates is refused, and nothing is made', function () {
    expect(fn () => standup($this->workflow, $this->organizer, ['frequency' => 'daily', 'until' => '2031-01-01']))
        ->toThrow(EventException::class, 'at most 100 dates');

    expect(Event::query()->count())->toBe(0)
        ->and(EventSeries::query()->count())->toBe(0);
});

test('a series running past two years is refused', function () {
    expect(fn () => standup($this->workflow, $this->organizer, ['frequency' => 'monthly', 'until' => '2032-03-05']))
        ->toThrow(EventException::class, 'two years');
});

test('a repeat has to say when it ends', function () {
    expect(fn () => standup($this->workflow, $this->organizer, ['frequency' => 'daily']))
        ->toThrow(EventException::class, 'when the repeat ends');
});

test('"this and following" moves later dates by the same shift, even across midnight', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'weekly', 'count' => 3], [
        'starts_at' => '2030-03-04T23:30',
        'ends_at' => '2030-03-05T00:15',
    ]);
    $second = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->skip(1)->firstOrFail();

    $this->workflow->update($second, [
        'title' => 'Late standup',
        'type' => 'meeting',
        'starts_at' => '2030-03-12T00:30',
        'ends_at' => '2030-03-12T01:30',
    ], scope: 'following');

    $events = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->get();

    expect(seriesLocalStarts($first))->toBe(['2030-03-04 23:30', '2030-03-12 00:30', '2030-03-19 00:30'])
        ->and($events->pluck('title')->all())->toBe(['Standup', 'Late standup', 'Late standup'])
        ->and((int) $events[2]->starts_at->diffInMinutes($events[2]->ends_at))->toBe(60);
});

test('"just this one" leaves the rest of the series alone', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'daily', 'count' => 3]);

    $this->workflow->update($first, [
        'title' => 'Standup (moved)',
        'type' => 'meeting',
        'starts_at' => '2030-03-04T10:00',
        'ends_at' => '2030-03-04T10:15',
    ]);

    expect(seriesLocalStarts($first))->toBe(['2030-03-04 10:00', '2030-03-05 09:00', '2030-03-06 09:00'])
        ->and(Event::query()->where('title', 'Standup')->count())->toBe(2);
});

test('archiving this and following leaves the earlier ones', function () {
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'daily', 'count' => 3]);
    $second = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->skip(1)->firstOrFail();

    $archived = $this->workflow->archive($second, scope: 'following');

    expect($archived)->toBe(2)
        ->and(Event::query()->where('series_id', $first->series_id)->pluck('id')->all())->toBe([$first->id]);
});

test('inviting to this and following invites to every later date, and tells each person once', function () {
    Notification::fake();
    $first = standup($this->workflow, $this->organizer, ['frequency' => 'daily', 'count' => 3]);
    $user = User::factory()->create(['is_active' => true]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    $invited = $this->workflow->invite($first, [$employee->id], $this->organizer, scope: 'following');

    expect($invited)->toBe(1)
        ->and($employee->eventAttendances()->count())->toBe(3)
        ->and($employee->eventAttendances()->whereNull('notified_at')->count())->toBe(0);

    Notification::assertSentToTimes($user, SystemNotification::class, 1);
    Notification::assertSentTo($user, SystemNotification::class, fn (SystemNotification $notice): bool => str_contains($notice->body, '3 dates'));
});
