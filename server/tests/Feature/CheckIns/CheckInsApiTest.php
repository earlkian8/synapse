<?php

use App\Models\CheckIn;
use App\Models\Employee;
use Laravel\Sanctum\Sanctum;

/*
| Check-ins on the phone (ADR 0102): the mobile app reads this month's questions and
| one's own months, and saves this month's answers — and nothing about anyone else.
*/

beforeEach(function () {
    $this->user = actingAsUserWith(['check-ins.respond']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'employment_status' => 'active']);
    Sanctum::actingAs($this->user);
});

function checkInApiAnswers(array $overrides = []): array
{
    return ['mood' => 3, 'satisfaction' => 3, 'energy' => 3, 'enthusiasm' => 3, 'immersion' => 3, 'leave_thoughts' => 3, ...$overrides];
}

test('the app reads the questions and one\'s own months, and the session says a check-in waits', function () {
    $this->getJson('/api/check-ins')->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.current', null)
        ->assertJsonCount(6, 'data.questions')
        ->assertJsonPath('data.questions.2.options.6.hint', 'Every day');

    $this->getJson('/api/me')->assertOk()
        ->assertJsonPath('user.can_check_in', true)
        ->assertJsonPath('user.check_in_due', true);
});

test('the app saves this month, then changes it', function () {
    $this->postJson('/api/check-ins', checkInApiAnswers(['mood' => 5, 'note' => 'Good month']))
        ->assertCreated()
        ->assertJsonPath('data.answers.mood', 5)
        ->assertJsonPath('data.note', 'Good month');
    $this->postJson('/api/check-ins', checkInApiAnswers(['mood' => 1]))
        ->assertOk()
        ->assertJsonPath('data.answers.mood', 1);

    expect(CheckIn::count())->toBe(1);
    $this->getJson('/api/me')->assertJsonPath('user.check_in_due', false);
});

test('an unanswered question, a turned-off workspace or an unlinked account is refused in words', function () {
    $this->postJson('/api/check-ins', array_diff_key(checkInApiAnswers(), ['mood' => 1]))->assertStatus(422)->assertJsonValidationErrors('mood');

    testOrganization()->update(['check_ins_enabled' => false]);
    $this->postJson('/api/check-ins', checkInApiAnswers())->assertStatus(422)->assertJsonPath('message', 'Check-ins are turned off in this workspace.');
    $this->getJson('/api/me')->assertJsonPath('user.can_check_in', false);

    testOrganization()->update(['check_ins_enabled' => true]);
    $this->employee->update(['user_id' => null]);
    $this->postJson('/api/check-ins', checkInApiAnswers())->assertStatus(422);
});

test('someone without check-ins.respond cannot reach it', function () {
    Sanctum::actingAs(actingAsUserWith(['leave.request']));

    $this->getJson('/api/check-ins')->assertForbidden();
    $this->postJson('/api/check-ins', checkInApiAnswers())->assertForbidden();
});
