<?php

use App\Models\OnboardingCase;
use App\Models\OnboardingTask;

test('a task is resolved when done or skipped', function () {
    expect((new OnboardingTask(['status' => 'done']))->isResolved())->toBeTrue()
        ->and((new OnboardingTask(['status' => 'skipped']))->isResolved())->toBeTrue()
        ->and((new OnboardingTask(['status' => 'pending']))->isResolved())->toBeFalse()
        ->and((new OnboardingTask(['status' => 'in_progress']))->isResolved())->toBeFalse();
});

test('a task with no due date is never overdue', function () {
    // No date cast is touched, so this stays DB-free; the date-dependent
    // overdue paths are exercised in the Feature suite.
    $task = new OnboardingTask(['status' => 'pending']);
    expect($task->isOverdue())->toBeFalse();
});

test('a case is active while pending or in progress', function () {
    expect((new OnboardingCase(['status' => 'pending']))->isActive())->toBeTrue()
        ->and((new OnboardingCase(['status' => 'in_progress']))->isActive())->toBeTrue()
        ->and((new OnboardingCase(['status' => 'completed']))->isActive())->toBeFalse()
        ->and((new OnboardingCase(['status' => 'cancelled']))->isActive())->toBeFalse();
});
