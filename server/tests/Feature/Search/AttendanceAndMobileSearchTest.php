<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Search\AttendanceSearchQuery;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0081 — attendance days in the ⌘K palette, when the words say attendance,
| and the mobile app's search of one's own records.
*/

function asDay(Employee $employee, string $date, array $attributes = []): AttendanceRecord
{
    return AttendanceRecord::query()->create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'status' => 'present',
        ...$attributes,
    ]);
}

test('the words are read for a day, a month, a kind of day and the person', function () {
    $q = AttendanceSearchQuery::parse(['maria', 'late', 'oct', '3'], '2026-10-11');

    expect($q->cued)->toBeTrue()
        ->and([$q->from, $q->to])->toBe(['2026-10-03', '2026-10-03'])
        ->and($q->kinds)->toBe(['late'])
        ->and($q->words)->toBe(['maria']);

    expect(AttendanceSearchQuery::parse(['santos', 'october', '2025'], '2026-10-11'))
        ->from->toBe('2025-10-01')->to->toBe('2025-10-31');
    expect(AttendanceSearchQuery::parse(['absent', 'yesterday'], '2026-10-11'))
        ->from->toBe('2026-10-10')->kinds->toBe(['absent']);
    expect(AttendanceSearchQuery::parse(['10/3/2026', 'on', 'leave'], '2026-10-11'))
        ->from->toBe('2026-10-03')->kinds->toBe(['on_leave'])->words->toBe([]);
    expect(AttendanceSearchQuery::parse(['maria', 'santos'], '2026-10-11')->cued)->toBeFalse();

    // "Leave" or "holiday" alone means the screen; beside a person, their days.
    expect(AttendanceSearchQuery::parse(['leave'], '2026-10-11')->cued)->toBeFalse()
        ->and(AttendanceSearchQuery::parse(['holidays'], '2026-10-11')->cued)->toBeFalse()
        ->and(AttendanceSearchQuery::parse(['maria', 'on', 'leave'], '2026-10-11'))
        ->cued->toBeTrue()->kinds->toBe(['on_leave'])->words->toBe(['maria']);
});

test('the palette finds attendance days by person and cue, never by a name alone', function () {
    testOrganization();
    $maria = Employee::factory()->create(['first_name' => 'Mariska', 'middle_name' => null, 'last_name' => 'Velasco', 'suffix' => null]);
    $other = Employee::factory()->create(['first_name' => 'Tomas', 'middle_name' => null, 'last_name' => 'Ibarra', 'suffix' => null]);
    asDay($maria, '2026-10-02', ['status' => 'late', 'late_minutes' => 12]);
    asDay($maria, '2026-10-05');
    asDay($other, '2026-10-02', ['status' => 'absent']);
    actingAsUserWith(['attendance.view']);

    $groups = collect($this->getJson('/search?q='.urlencode('mariska late'))->assertOk()->json('groups'))->keyBy('key');

    expect($groups['attendance']['items'])->toHaveCount(1)
        ->and($groups['attendance']['items'][0]['subtitle'])->toStartWith('Fri, Oct 2, 2026')
        ->and($groups['attendance']['items'][0]['href'])->toContain('/attendance?date=2026-10-02')->toContain('open=');

    $absent = collect($this->getJson('/search?q='.urlencode('absent oct 2'))->json('groups'))->keyBy('key');
    expect(collect($absent['attendance']['items'])->pluck('title')->all())->toBe(['Tomas Ibarra']);

    $name = collect($this->getJson('/search?q=mariska')->json('groups'))->keyBy('key');
    expect($name)->not->toHaveKey('attendance');
});

test('attendance days need attendance.view', function () {
    testOrganization();
    $maria = Employee::factory()->create(['first_name' => 'Mariska', 'last_name' => 'Velasco']);
    asDay($maria, '2026-10-02', ['status' => 'late', 'late_minutes' => 12]);
    actingAsUserWith(['employees.view']);

    $groups = collect($this->getJson('/search?q='.urlencode('mariska late'))->json('groups'))->keyBy('key');

    expect($groups)->not->toHaveKey('attendance');
});

test('the app searches only the person\'s own records, and says where each opens', function () {
    $user = actingAsUserWith(['leave.request']);
    $me = Employee::factory()->create(['user_id' => $user->id]);
    $someone = Employee::factory()->create();
    $sick = LeaveType::factory()->create(['name' => 'Sick Leave']);
    $mine = LeaveRequest::factory()->create(['employee_id' => $me->id, 'leave_type_id' => $sick->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-06']);
    LeaveRequest::factory()->create(['employee_id' => $someone->id, 'leave_type_id' => $sick->id]);
    asDay($me, '2026-10-02', ['status' => 'late', 'late_minutes' => 9]);
    asDay($someone, '2026-10-02', ['status' => 'late', 'late_minutes' => 30]);
    Holiday::create(['name' => 'Good Friday', 'type' => 'regular', 'recurrence' => 'easter', 'rule' => ['offset' => -2]]);

    Sanctum::actingAs($user);

    $leave = collect($this->getJson('/api/search?q='.urlencode('sick leave'))->assertOk()->json('groups'))->keyBy('key');
    expect($leave['leave']['items'])->toHaveCount(1)
        ->and($leave['leave']['items'][0]['target'])->toBe(['screen' => 'leave', 'id' => $mine->id]);

    $late = collect($this->getJson('/api/search?q='.urlencode('late october'))->json('groups'))->keyBy('key');
    expect($late['attendance']['items'])->toHaveCount(1)
        ->and($late['attendance']['items'][0]['target'])->toBe(['screen' => 'attendance', 'date' => '2026-10-02']);

    $holiday = collect($this->getJson('/api/search?q='.urlencode('good friday'))->json('groups'))->keyBy('key');
    expect($holiday['holidays']['items'][0]['target'])->toBe(['screen' => 'holidays']);

    $this->getJson('/api/search?q=a')->assertUnprocessable();
});

test('somebody with no employee record finds nothing of their own', function () {
    $user = User::factory()->create();
    testOrganization();
    Sanctum::actingAs($user);

    $this->getJson('/api/search?q='.urlencode('sick leave'))->assertOk()->assertJsonPath('groups', []);
});
