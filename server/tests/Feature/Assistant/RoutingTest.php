<?php

use App\Models\ActivityLog;
use App\Models\JobPosting;
use App\Services\Assistant\Assistant;

/*
| Per-turn tool routing (ADR 0068 §2). A request carries the tools of the
| modules it is about, plus `load_tools` for the rest. Routing decides what is
| *shown*; permission still decides what may *run*.
*/

test('a request about a CV and a posting carries the recruitment tools, not the whole system', function () {
    actingAsUserWith(['recruitment.view', 'recruitment.create', 'leave.view', 'training.view', 'employees.view']);
    $model = fakeAssistantModel([[['text' => 'Sure.']]]);

    app(Assistant::class)->handle(auth()->user(), 'put this cv in the cybersecurity analyst posting');

    expect($model->toolNames())->toContain('add_application', 'find_job_postings', 'load_tools')
        ->not->toContain('file_leave_request')
        ->not->toContain('enroll_in_training');
});

test('a request about leave carries the leave tools', function () {
    actingAsUserWith(['recruitment.view', 'leave.view', 'leave.manage']);
    $model = fakeAssistantModel([[['text' => 'Sure.']]]);

    app(Assistant::class)->handle(auth()->user(), "approve Maria's leave");

    expect($model->toolNames())->toContain('review_leave_request')
        ->not->toContain('add_application');
});

test('the instruction carries guidance only for the routed modules, and a catalogue of the rest', function () {
    actingAsUserWith(['recruitment.view', 'training.view']);
    $model = fakeAssistantModel([[['text' => 'Sure.']]]);

    app(Assistant::class)->handle(auth()->user(), 'list the job postings');

    expect($model->sent[0]['system'])->toContain('RECRUITMENT —')
        ->toContain('OTHER CAPABILITIES')
        ->toContain('- training:');
});

test('load_tools brings a module’s tools and guidance into the next request', function () {
    actingAsUserWith(['recruitment.view', 'training.view']);
    $model = fakeAssistantModel([
        [modelCall('load_tools', ['modules' => ['training']])],
        [['text' => 'Loaded.']],
    ]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'help me with the other thing');

    expect($model->toolNames(0))->not->toContain('find_training_programs')
        ->and($model->toolNames(1))->toContain('find_training_programs')
        ->and($model->sent[1]['system'])->toContain('TRAINING')
        ->and($turn['reply'])->toBe('Loaded.');
});

test('load_tools cannot load a module the user lacks, and does not name it', function () {
    actingAsUserWith(['recruitment.view']);
    $model = fakeAssistantModel([
        [modelCall('load_tools', ['modules' => ['users', 'roles']])],
        [['text' => 'Nope.']],
    ]);

    app(Assistant::class)->handle(auth()->user(), 'list job postings');

    $response = collect($model->sent[1]['contents'])->last()['parts'][0]['functionResponse']['response'];

    expect($model->toolNames(1))->not->toContain('find_users')
        ->and(json_encode($response))->not->toContain('users')
        ->and(json_encode($response))->not->toContain('roles');
});

test('a permitted tool that was not shown this turn still runs', function () {
    actingAsUserWith(['recruitment.view', 'training.view']);
    JobPosting::factory()->create(['title' => 'Cybersecurity Analyst', 'status' => 'open']);
    $model = fakeAssistantModel([
        [modelCall('find_job_postings', ['query' => 'Cybersecurity'])],
        [['text' => 'Found it.']],
    ]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'what training programs are there');

    expect($model->toolNames(0))->not->toContain('find_job_postings')
        ->and($turn['steps'][0]['status'])->toBe('done')
        ->and($turn['actions'][0]['title'])->toBe('Cybersecurity Analyst');
});

test('a tool the user may not use is still refused and logged', function () {
    actingAsUserWith(['recruitment.view']);
    fakeAssistantModel([
        [modelCall('archive_employee', ['employee' => 'Maria'])],
        [['text' => 'Done.']],
    ]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'archive Maria');

    expect($turn['steps'][0]['label'])->toBe('Refused an action')
        ->and(ActivityLog::where('event', 'blocked')->exists())->toBeTrue();
});

test('a follow-up keeps the tools of the module the last turn used', function () {
    actingAsUserWith(['recruitment.view', 'recruitment.manage-pipeline', 'training.view']);
    $model = fakeAssistantModel([[['text' => 'Sure.']]]);

    app(Assistant::class)->handle(auth()->user(), 'now move him along', [
        ['role' => 'user', 'text' => 'add Ana to the analyst posting'],
        ['role' => 'assistant', 'text' => 'Added Ana.', 'modules' => ['recruitment']],
    ]);

    expect($model->toolNames())->toContain('move_application');
});

test('the system guide is always carried', function () {
    actingAsUserWith(['recruitment.view']);
    $model = fakeAssistantModel([[['text' => 'Hello.']]]);

    app(Assistant::class)->handle(auth()->user(), 'hello');

    expect($model->toolNames())->toContain('find_help');
});

test('a typical turn carries a fraction of the whole tool set', function () {
    actingAsSuperAdmin();
    $model = fakeAssistantModel([[['text' => 'Sure.']]]);

    app(Assistant::class)->handle(auth()->user(), 'put this cv in the cybersecurity analyst posting');

    $sent = strlen(json_encode($model->sent[0]['tools'])) + strlen($model->sent[0]['system']);

    expect($sent)->toBeLessThan(45_000);
});
