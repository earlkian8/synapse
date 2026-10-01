<?php

use App\Models\Employee;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Assistant\Modules\OnboardingModule;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Users are identities shared across workspaces, not tenant rows: `users` has no
| organization_id, and no global scope keeps a query on them inside one company.
| Every place that picks a user — a recipient, an assignee, an interviewer, the
| account an employee is linked to — has to ask for members of this workspace,
| or it reaches (and tells people about) somebody in another company.
*/

/** A user who belongs to another workspace only. */
function outsider(string $first = 'Zed', string $last = 'Outsider'): User
{
    return app(Tenancy::class)->runFor(
        Organization::factory()->create(),
        fn () => User::factory()->create(['first_name' => $first, 'last_name' => $last, 'is_active' => true]),
    );
}

test('an announcement to everyone reaches this workspace only', function () {
    Notification::fake();
    actingAsSuperAdmin();
    $colleague = User::factory()->create(['is_active' => true]);
    $stranger = outsider();

    $this->post(route('system.notifications.store'), [
        'audience' => 'all', 'title' => 'Office closed Friday', 'body' => 'Typhoon warning.', 'level' => 'info',
    ])->assertRedirect();

    Notification::assertSentTo($colleague, SystemNotification::class);
    Notification::assertNotSentTo($stranger, SystemNotification::class);
});

test('the compose page lists this workspace’s people, and a stranger cannot be messaged', function () {
    Notification::fake();
    actingAsSuperAdmin();
    $stranger = outsider();

    $this->get(route('system.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'audiences.users',
            fn ($users): bool => collect($users)->doesntContain(fn (array $u): bool => $u['id'] === $stranger->id),
        ));

    $this->post(route('system.notifications.store'), [
        'audience' => 'user', 'user_id' => $stranger->id, 'title' => 'Hello', 'body' => 'Hi.', 'level' => 'info',
    ])->assertSessionHasErrors('user_id');

    Notification::assertNothingSent();
});

test('an onboarding task cannot be assigned to somebody in another workspace', function () {
    Notification::fake();
    actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();

    $this->post(route('onboarding.tasks.store', $case), ['title' => 'Set up laptop', 'category' => OnboardingTask::CATEGORIES[0], 'assigned_to' => outsider()->id])
        ->assertSessionHasErrors('assigned_to');

    expect($case->tasks()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('an employee cannot be linked to another workspace’s account, nor a posting to its pipeline', function () {
    actingAsSuperAdmin();
    $employee = Employee::factory()->create();
    $theirPipeline = app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => RecruitmentPipeline::factory()->withStandardStages()->create());

    $this->post(route('employees.update', $employee), [...$employee->only(['first_name', 'last_name', 'email', 'employee_no', 'employment_type', 'employment_status']), 'date_hired' => $employee->date_hired?->toDateString(), 'user_id' => outsider()->id])
        ->assertSessionHasErrors('user_id');

    $this->post(route('recruitment.store'), ['title' => 'Analyst', 'recruitment_pipeline_id' => $theirPipeline->id])
        ->assertSessionHasErrors('recruitment_pipeline_id');
});

test('the assistant resolves an assignee among this workspace’s members only', function () {
    Notification::fake();
    $user = actingAsSuperAdmin();
    $case = OnboardingCase::factory()->create();
    $name = $case->employee->full_name;
    outsider('Olga', 'Elsewhere');

    $result = app(OnboardingModule::class)->run($user, 'add_onboarding_task', ['employee' => $name, 'title' => 'Set up laptop', 'assignee' => 'Olga Elsewhere']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('No active user in this workspace')
        ->and($case->tasks()->where('title', 'Set up laptop')->exists())->toBeFalse();

    Notification::assertNothingSent();
});
