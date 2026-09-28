<?php

use App\Models\ActivityLog;
use App\Models\ClearanceItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Modules\OffboardingModule;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Ai\GeminiClient;
use App\Support\Tenancy;

/*
| The offboarding capability of the assistant: who is leaving, their clearance,
| and the exit itself — driven by tool name, never through Gemini (except where
| the whole turn matters, and then a scripted model plays it).
*/

function offboardingAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(OffboardingModule::class)->run($user, $tool, $args);
}

function offboardingAgentTools(User $user): array
{
    return array_column(app(OffboardingModule::class)->tools($user), 'name');
}

function leaver(string $first, string $last, ?Department $department = null): Employee
{
    return Employee::factory()->create([
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
        'suffix' => null,
        'employment_status' => 'active',
        'department_id' => $department?->id,
    ]);
}

/** An exit in clearance with the given items (label => [status, department]). */
function exitFor(Employee $employee, array $items = [], string $status = 'clearance', ?string $lastDay = null): OffboardingCase
{
    $case = OffboardingCase::factory()->create([
        'employee_id' => $employee->id,
        'type' => 'resignation',
        'status' => $status,
        'reason' => 'Moving abroad.',
        'last_working_day' => $lastDay ?? today()->addDays(10)->toDateString(),
    ]);

    $order = 0;

    foreach ($items as $label => [$itemStatus, $department]) {
        $case->clearanceItems()->create([
            'item' => $label,
            'status' => $itemStatus,
            'department_id' => $department?->id,
            'sort_order' => $order++,
        ]);
    }

    return $case;
}

/** A model that answers every turn with the one call it was given. */
function offboardingModel(array $call): GeminiClient
{
    return new class($call) extends GeminiClient
    {
        public function __construct(private readonly array $call)
        {
            parent::__construct(null, 'stub');
        }

        public function configured(): bool
        {
            return true;
        }

        public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
        {
            return ['candidates' => [['content' => ['parts' => [['functionCall' => $this->call]]]]]];
        }
    };
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; a manager changes', function () {
    $viewer = actingAsUserWith(['offboarding.view']);

    expect(offboardingAgentTools($viewer))->toEqualCanonicalizing([
        'find_offboarding_cases', 'get_offboarding_case', 'find_clearance_items', 'offboarding_summary',
    ]);

    $manager = actingAsUserWith(['offboarding.view', 'offboarding.manage']);

    expect(offboardingAgentTools($manager))->toContain('start_offboarding', 'set_offboarding_status', 'set_clearance_status');
});

test('a write is refused at run time without offboarding.manage', function () {
    actingAsSuperAdmin();
    exitFor(leaver('Maria', 'Santos'));

    $viewer = actingAsUserWith(['offboarding.view']);
    $result = offboardingAgent($viewer, 'set_offboarding_status', ['employee' => 'Maria Santos', 'action' => 'complete']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('permission')
        ->and(OffboardingCase::query()->value('status'))->toBe('clearance');
});

test('starting, closing, deleting, bulk sign-off and removal are what wait for confirmation', function () {
    $module = app(OffboardingModule::class);

    foreach (['start_offboarding', 'set_offboarding_status', 'delete_offboarding_case', 'clear_pending_clearance', 'remove_clearance_item'] as $tool) {
        expect($module->requiresConfirmation($tool))->toBeTrue("{$tool} runs without confirmation");
    }

    expect($module->requiresConfirmation('set_clearance_status'))->toBeFalse()
        ->and($module->requiresConfirmation('add_clearance_item'))->toBeFalse();
});

test('starting an exit asked for plainly still waits for the Confirm', function () {
    $user = actingAsSuperAdmin();
    $maria = leaver('Maria', 'Santos');

    app()->instance(GeminiClient::class, offboardingModel(['name' => 'start_offboarding', 'args' => ['employee' => 'Maria Santos', 'exit_type' => 'resignation']]));
    app()->forgetInstance(Assistant::class);

    $turn = app(Assistant::class)->handle($user, 'start offboarding for maria santos, she resigned');

    expect($turn['actions'][0]['kind'])->toBe('confirm')
        ->and($maria->offboardingCase()->exists())->toBeFalse();
});

// ── The exit ─────────────────────────────────────────────────────────────────

test('starting an exit seeds the checklist, audited via assistant', function () {
    $user = actingAsSuperAdmin();
    $maria = leaver('Maria', 'Santos');

    $result = offboardingAgent($user, 'start_offboarding', [
        'employee' => 'Maria Santos', 'exit_type' => 'resignation', 'notice_date' => '2026-09-01', 'last_working_day' => '2026-09-30',
    ]);

    $case = $maria->offboardingCase()->first();

    expect($result->failed())->toBeFalse()
        ->and($case->status)->toBe('initiated')
        ->and($case->clearanceItems()->count())->toBeGreaterThan(0)
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->latest('id')->value('description'))->toBe('Started offboarding for Maria Santos via assistant');

    // Once is enough — and a last day before the notice is refused.
    expect(offboardingAgent($user, 'start_offboarding', ['employee' => 'Maria Santos', 'exit_type' => 'resignation'])->detail)->toBe('That employee is already being offboarded.')
        ->and(offboardingAgent($user, 'start_offboarding', ['employee' => leaver('Ben', 'Cruz')->employee_no, 'exit_type' => 'termination', 'notice_date' => '2026-09-10', 'last_working_day' => '2026-09-01'])->failed())->toBeTrue();
});

test('completing separates the employee and says what was left outstanding; the lifecycle is guarded', function () {
    $user = actingAsSuperAdmin();
    $maria = leaver('Maria', 'Santos');
    exitFor($maria, ['Return laptop' => ['cleared', null], 'Final pay' => ['pending', null]]);

    $done = offboardingAgent($user, 'set_offboarding_status', ['employee' => 'Maria Santos', 'action' => 'complete']);

    expect($done->failed())->toBeFalse()
        ->and($done->detail)->toContain('now resigned')->toContain('1 clearance item was not signed off')
        ->and($maria->refresh()->employment_status)->toBe('resigned');

    expect(offboardingAgent($user, 'set_offboarding_status', ['employee' => 'Maria Santos', 'action' => 'cancel'])->detail)->toBe('This exit is already completed.');

    $reopened = offboardingAgent($user, 'set_offboarding_status', ['employee' => 'Maria Santos', 'action' => 'reopen']);

    expect($reopened->failed())->toBeFalse()
        ->and($maria->refresh()->employment_status)->toBe('active')
        ->and(OffboardingCase::query()->value('status'))->toBe('clearance')
        ->and(offboardingAgent($user, 'set_offboarding_status', ['employee' => 'Maria Santos', 'action' => 'reopen'])->detail)->toContain('still in progress');
});

test('deleting an exit leaves the employment status alone', function () {
    $user = actingAsSuperAdmin();
    $maria = leaver('Maria', 'Santos');
    exitFor($maria, [], status: 'completed');
    $maria->update(['employment_status' => 'resigned']);

    expect(offboardingAgent($user, 'delete_offboarding_case', ['employee' => 'Maria Santos'])->failed())->toBeFalse()
        ->and(OffboardingCase::query()->count())->toBe(0)
        ->and($maria->refresh()->employment_status)->toBe('resigned');
});

// ── The checklist ────────────────────────────────────────────────────────────

test('signing off and flagging stamp and audit, and nudge the exit into clearance', function () {
    $user = actingAsSuperAdmin();
    $case = exitFor(leaver('Maria', 'Santos'), ['Return laptop & charger' => ['pending', null], 'Revoke system accounts' => ['pending', null]], status: 'initiated');

    offboardingAgent($user, 'set_clearance_status', ['employee' => 'Maria Santos', 'item' => 'laptop', 'status' => 'cleared']);
    offboardingAgent($user, 'set_clearance_status', ['employee' => 'Maria Santos', 'item' => 'Revoke system accounts', 'status' => 'flagged', 'remarks' => 'Still has VPN access']);

    $laptop = ClearanceItem::query()->where('item', 'like', 'Return laptop%')->first();
    $accounts = ClearanceItem::query()->where('item', 'Revoke system accounts')->first();

    expect($laptop->status)->toBe('cleared')
        ->and($laptop->cleared_by)->toBe($user->id)
        ->and($accounts->status)->toBe('flagged')
        ->and($accounts->remarks)->toBe('Still has VPN access')
        ->and($case->refresh()->status)->toBe('clearance')
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->where('description', 'like', 'Flagged clearance item%via assistant')->exists())->toBeTrue();
});

test('an item label that matches several is refused, never guessed', function () {
    $user = actingAsSuperAdmin();
    exitFor(leaver('Maria', 'Santos'), ['Return laptop' => ['pending', null], 'Return ID card' => ['pending', null]]);

    $result = offboardingAgent($user, 'set_clearance_status', ['employee' => 'Maria Santos', 'item' => 'Return', 'status' => 'cleared']);

    expect($result->failed())->toBeTrue()
        ->and($result->detail)->toContain('More than one item')
        ->and(ClearanceItem::query()->where('status', 'cleared')->count())->toBe(0);
});

test('clearing pending items leaves flagged ones, and can be one department’s', function () {
    $user = actingAsSuperAdmin();
    $it = Department::factory()->create(['name' => 'Information Technology']);
    exitFor(leaver('Maria', 'Santos'), [
        'Return laptop' => ['pending', $it],
        'Revoke accounts' => ['flagged', $it],
        'Final pay' => ['pending', null],
    ]);

    $result = offboardingAgent($user, 'clear_pending_clearance', ['employee' => 'Maria Santos', 'scope' => 'department', 'department' => 'Information Technology']);

    expect($result->failed())->toBeFalse()
        ->and(ClearanceItem::query()->where('item', 'Return laptop')->value('status'))->toBe('cleared')
        ->and(ClearanceItem::query()->where('item', 'Revoke accounts')->value('status'))->toBe('flagged')
        ->and(ClearanceItem::query()->where('item', 'Final pay')->value('status'))->toBe('pending')
        ->and(offboardingAgent($user, 'clear_pending_clearance', ['employee' => 'Maria Santos', 'scope' => 'department', 'department' => 'Information Technology'])->detail)->toBe('Nothing pending to clear there.');
});

test('items are added, edited and removed; a department must be this workspace’s', function () {
    $user = actingAsSuperAdmin();
    Department::factory()->create(['name' => 'Finance']);
    $case = exitFor(leaver('Maria', 'Santos'));

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => Department::factory()->create(['name' => 'Legal']));

    expect(offboardingAgent($user, 'add_clearance_item', ['employee' => 'Maria Santos', 'label' => 'Return parking pass', 'department' => 'Legal'])->failed())->toBeTrue()
        ->and(offboardingAgent($user, 'add_clearance_item', ['employee' => 'Maria Santos', 'label' => 'Return parking pass', 'department' => 'Finance'])->failed())->toBeFalse()
        ->and(offboardingAgent($user, 'update_clearance_item', ['employee' => 'Maria Santos', 'item' => 'parking', 'new_label' => 'Return parking pass & fob'])->failed())->toBeFalse()
        ->and($case->clearanceItems()->value('item'))->toBe('Return parking pass & fob')
        ->and(offboardingAgent($user, 'remove_clearance_item', ['employee' => 'Maria Santos', 'item' => 'parking'])->failed())->toBeFalse()
        ->and($case->clearanceItems()->count())->toBe(0);
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('reading an exit spells out the checklist and is audited as viewed', function () {
    $user = actingAsSuperAdmin();
    exitFor(leaver('Maria', 'Santos'), ['Return laptop' => ['cleared', null], 'Revoke accounts' => ['flagged', null]]);

    $card = offboardingAgent($user, 'get_offboarding_case', ['employee' => 'Maria Santos'])->cards[0];

    expect($card['tone'])->toBe('warning')
        ->and(implode(' | ', $card['meta']))
        ->toContain('1 of 2 signed off, 1 flagged')
        ->toContain('Reason: Moving abroad.')
        ->toContain('Flagged: Revoke accounts')
        ->and(ActivityLog::query()->where('log_name', 'offboarding')->where('event', 'viewed')->exists())->toBeTrue();
});

test('pending items can be listed by department across exits in progress', function () {
    $user = actingAsSuperAdmin();
    $it = Department::factory()->create(['name' => 'Information Technology']);
    exitFor(leaver('Maria', 'Santos'), ['Return laptop' => ['pending', $it], 'Final pay' => ['pending', null]]);
    exitFor(leaver('Ben', 'Cruz'), ['Return phone' => ['pending', $it]], status: 'completed');

    $result = offboardingAgent($user, 'find_clearance_items', ['department' => 'Information Technology']);

    expect(array_column($result->cards, 'title'))->toBe(['Return laptop']);
});

test('another workspace’s exits are invisible', function () {
    $user = actingAsSuperAdmin();

    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => exitFor(leaver('Zed', 'Outsider')));

    expect(offboardingAgent($user, 'get_offboarding_case', ['employee' => 'Zed Outsider'])->failed())->toBeTrue()
        ->and(offboardingAgent($user, 'find_offboarding_cases')->cards)->toBe([]);
});

test('a person’s brief carries their exit, and needs offboarding.view', function () {
    actingAsSuperAdmin();
    $maria = leaver('Maria', 'Santos');
    exitFor($maria, ['Revoke accounts' => ['flagged', null]]);

    $section = app(OffboardingModule::class)->contextFor(actingAsUserWith(['offboarding.view']), RetrievedSubject::employee($maria));

    expect($section?->toPrompt())->toContain('Resignation — clearance')->toContain('Flagged: Revoke accounts')
        ->and(app(OffboardingModule::class)->contextFor(actingAsUserWith(['employees.view']), RetrievedSubject::employee($maria)))->toBeNull();
});

test('a question about who is leaving reads the board', function () {
    $user = actingAsSuperAdmin();
    exitFor(leaver('Maria', 'Santos'), ['Revoke accounts' => ['flagged', null]], lastDay: today()->addDays(5)->toDateString());

    $brief = app(Retriever::class)->retrieve($user, 'who is leaving soon?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())->toContain('In offboarding: 1')->toContain('Leaving next: Maria Santos');
});
