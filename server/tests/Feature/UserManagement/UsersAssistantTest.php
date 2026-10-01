<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Modules\UsersModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\Ai\GeminiClient;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/*
| The users capability of the assistant: the sign-in accounts of this
| workspace, changed by the Users screen's own path and rules — and never a
| step past them. Gemini is never called.
*/

beforeEach(fn () => Notification::fake());

function accountsAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(UsersModule::class)->run($user, $tool, $args);
}

/** A member of this workspace with a plain name (the factory adds suffixes). */
function member(string $first, string $last, array $attributes = []): User
{
    testOrganization();

    return User::factory()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null, ...$attributes]);
}

test('only the screen’s permissions open the tools; access changes wait for a confirm; passwords are never offered', function () {
    $module = app(UsersModule::class);
    $viewer = actingAsUserWith(['users.view']);
    $admin = actingAsSuperAdmin();

    expect($module->isAvailable(actingAsUserWith(['roles.view'])))->toBeFalse()
        ->and(array_column($module->tools($viewer), 'name'))->toBe(['find_users', 'get_user'])
        ->and(array_column($module->tools($admin), 'name'))->not->toContain('reset_user_password')
        ->and($module->requiresConfirmation('give_user_role'))->toBeTrue()
        ->and($module->requiresConfirmation('set_user_active'))->toBeTrue()
        ->and($module->requiresConfirmation('change_user_email'))->toBeTrue()
        ->and($module->requiresConfirmation('archive_user'))->toBeTrue()
        ->and($module->requiresConfirmation('create_user'))->toBeTrue()
        ->and($module->requiresConfirmation('update_user'))->toBeFalse()
        ->and(accountsAgent($viewer, 'set_user_active', ['user' => 'x', 'active' => false])->detail)->toContain("don't have permission");
});

test('accounts are listed by status, role and what they can do — in this workspace only', function () {
    $user = actingAsSuperAdmin();
    $approver = makeRole('leave-approver', ['leave.view', 'leave.manage']);
    member('Lena', 'Approves')->roles()->attach($approver);
    member('Ivan', 'Inactive', ['is_active' => false]);
    member('Uma', 'Unverified', ['email_verified_at' => null]);
    app(Tenancy::class)->runFor(Organization::factory()->create(), fn () => User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Outside']));

    $canApprove = collect(accountsAgent($user, 'find_users', ['can' => 'approve / reject leave'])->cards)->pluck('title');
    $inactive = collect(accountsAgent($user, 'find_users', ['status' => 'inactive'])->cards)->pluck('title');
    $unverified = collect(accountsAgent($user, 'find_users', ['status' => 'unverified'])->cards)->pluck('title');
    $byRole = accountsAgent($user, 'find_users', ['role' => 'Leave Approver']);

    expect($canApprove->all())->toContain('Lena Approves', $user->full_name)
        ->and($canApprove)->not->toContain('Ivan Inactive')
        ->and($inactive->all())->toBe(['Ivan Inactive'])
        ->and($unverified->all())->toContain('Uma Unverified')
        ->and($byRole->label)->toBe('Listed accounts with the Leave Approver role')
        ->and(accountsAgent($user, 'find_users', ['search' => 'Olga'])->cards)->toBe([])
        ->and(accountsAgent($user, 'get_user', ['user' => 'Olga Outside'])->failed())->toBeTrue();
});

test('an account reads out its roles and what they let it do', function () {
    $user = actingAsSuperAdmin();
    $lena = member('Lena', 'Approves');
    $lena->roles()->attach(makeRole('leave-approver', ['leave.view', 'leave.manage']));

    $meta = implode(' | ', accountsAgent($user, 'get_user', ['user' => 'lena approves'])->cards[0]['meta']);

    expect($meta)->toContain('Roles: Leave Approver')
        ->toContain('Can: Leave Management 2/3')
        ->toContain('Not linked to an employee record');
});

test('somebody is added with roles the actor may give — and not with one they may not', function () {
    makeRole(Role::SUPER_ADMIN, [], true);
    makeRole('viewer', ['users.view']);
    $user = actingAsUserWith(['users.view', 'users.create', 'roles.assign']);

    $added = accountsAgent($user, 'create_user', ['first_name' => 'Nina', 'last_name' => 'New', 'email' => 'Nina.New@Example.com', 'roles' => ['Viewer']]);
    $refused = accountsAgent($user, 'create_user', ['first_name' => 'Evan', 'last_name' => 'Escalate', 'email' => 'evan@example.com', 'roles' => ['Hr Manager']]);
    $twice = accountsAgent($user, 'create_user', ['first_name' => 'Nina', 'last_name' => 'Again', 'email' => 'nina.new@example.com']);

    $nina = User::query()->where('email', 'nina.new@example.com')->sole();

    expect($added->failed())->toBeFalse()
        ->and($added->detail)->toContain('verification email was sent')
        ->and($nina->roles->pluck('name')->all())->toBe(['viewer'])
        ->and($refused->detail)->toContain('can make somebody an')
        ->and(User::query()->where('email', 'evan@example.com')->exists())->toBeFalse()
        ->and($twice->detail)->toContain('already belongs to a user in this organisation')
        ->and(ActivityLog::query()->where('description', 'Created user Nina New via assistant')->exists())->toBeTrue();
});

test('names are corrected; the email and whether they can sign in change as on the screen', function () {
    $user = actingAsSuperAdmin();
    $maria = member('Maria', 'Santos', ['phone_number' => null]);
    $maria->createToken('phone');

    accountsAgent($user, 'update_user', ['user' => 'Maria Santos', 'last_name' => 'Santos-Reyes', 'phone_number' => '0917 555 0101']);
    $email = accountsAgent($user, 'change_user_email', ['user' => 'Maria Santos-Reyes', 'email' => 'maria.reyes@example.com']);
    $off = accountsAgent($user, 'set_user_active', ['user' => 'maria.reyes@example.com', 'active' => false]);
    $again = accountsAgent($user, 'set_user_active', ['user' => 'maria.reyes@example.com', 'active' => false]);
    $self = accountsAgent($user, 'set_user_active', ['user' => $user->email, 'active' => false]);

    $maria->refresh();

    expect($maria->last_name)->toBe('Santos-Reyes')
        ->and($maria->phone_number)->toBe('0917 555 0101')
        ->and($email->failed())->toBeFalse()
        ->and($maria->email)->toBe('maria.reyes@example.com')
        ->and($maria->email_verified_at)->toBeNull()
        ->and($off->failed())->toBeFalse()
        ->and($maria->is_active)->toBeFalse()
        ->and($maria->tokens()->count())->toBe(0)
        ->and($again->detail)->toContain('is already inactive')
        ->and($self->detail)->toBe('You cannot deactivate your own account.');
});

test('roles are given and taken by the grant rules, and the card says what changes hands', function () {
    $manager = makeRole(Role::SUPER_ADMIN, [], true);
    $approver = makeRole('leave-approver', ['leave.view', 'leave.manage']);
    $exporter = makeRole('exporter', ['employees.export']);
    $user = actingAsUserWith(['users.view', 'roles.assign', 'leave.view', 'leave.manage']);
    $jon = member('Jon', 'Doe');
    $module = app(UsersModule::class);

    expect($module->consequence($user, 'give_user_role', ['user' => 'Jon Doe', 'role' => 'Leave Approver']))
        ->toBe('Jon Doe would gain 2 permissions: View leave requests & balances, Approve / reject leave & set balances.');

    $given = accountsAgent($user, 'give_user_role', ['user' => 'Jon Doe', 'role' => 'leave approver']);
    $twice = accountsAgent($user, 'give_user_role', ['user' => 'Jon Doe', 'role' => 'Leave Approver']);
    $beyond = accountsAgent($user, 'give_user_role', ['user' => 'Jon Doe', 'role' => 'Exporter']);
    $himself = accountsAgent($user, 'give_user_role', ['user' => $user->email, 'role' => $manager->label]);

    expect($module->consequence($user, 'take_user_role', ['user' => 'Jon Doe', 'role' => 'Leave Approver']))->toContain('would lose 2 permissions');

    $taken = accountsAgent($user, 'take_user_role', ['user' => 'Jon Doe', 'role' => 'Leave Approver']);

    expect($given->failed())->toBeFalse()
        ->and($twice->detail)->toContain('already has the Leave Approver role')
        ->and($beyond->detail)->toContain("grants access you don't have yourself (Export employees)")
        ->and($himself->detail)->toContain('can make somebody an')
        ->and($user->fresh()->isSuperAdmin())->toBeFalse()
        ->and($taken->failed())->toBeFalse()
        ->and($jon->fresh()->roles)->toHaveCount(0)
        ->and(ActivityLog::query()->where('description', 'Gave Jon Doe the Leave Approver role via assistant')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('description', 'Took the Leave Approver role from Jon Doe via assistant')->exists())->toBeTrue();
});

test('archiving removes a shared account from this workspace only; nobody reaches past their access', function () {
    $user = actingAsUserWith(['users.view', 'users.delete', 'users.manage-status', 'users.update']);
    $boss = member('Bea', 'Boss');
    $boss->roles()->attach(makeRole(Role::SUPER_ADMIN, [], true));
    $plain = member('Pat', 'Plain');

    $elsewhere = Organization::factory()->create();
    $shared = member('Sam', 'Shared');
    OrganizationProvisioner::addMember($elsewhere, $shared);

    expect(app(UsersModule::class)->consequence($user, 'archive_user', ['user' => 'Sam Shared']))->toContain('belongs to another workspace too');

    $removed = accountsAgent($user, 'archive_user', ['user' => 'Sam Shared']);
    $archived = accountsAgent($user, 'archive_user', ['user' => 'Pat Plain']);
    $boss1 = accountsAgent($user, 'archive_user', ['user' => 'Bea Boss']);
    $boss2 = accountsAgent($user, 'change_user_email', ['user' => 'Bea Boss', 'email' => 'mine@example.com']);

    expect($removed->label)->toBe('Removed Sam Shared from this workspace')
        ->and($shared->fresh()->trashed())->toBeFalse()
        ->and($shared->fresh()->isMemberOf($elsewhere))->toBeTrue()
        ->and($shared->fresh()->isMemberOf(testOrganization()))->toBeFalse()
        ->and($archived->label)->toBe('Archived Pat Plain')
        ->and($plain->fresh()->trashed())->toBeTrue()
        ->and($boss1->detail)->toContain("has access you don't")
        ->and($boss2->detail)->toContain("has access you don't")
        ->and($boss->fresh()->email)->not->toBe('mine@example.com');
});

test('a question about accounts is answered from the brief', function () {
    $user = actingAsSuperAdmin();
    member('Ivan', 'Inactive', ['is_active' => false]);

    expect(app(Retriever::class)->retrieve($user, 'how many user accounts do we have?')?->toPrompt())
        ->toContain('accounts in this workspace: 1 active, 1 inactive')
        ->toContain('HR Managers (every permission): '.$user->full_name);
});

test('a role the model asks to give is held for Confirm with what it grants, and runs only then', function () {
    $user = actingAsSuperAdmin();
    foreach (['assistant-min:', 'assistant-day:', 'assistant-actions:'] as $key) {
        RateLimiter::clear($key.$user->id);
    }
    $jon = member('Jon', 'Doe');
    makeRole('leave-approver', ['leave.view']);

    app()->instance(GeminiClient::class, new class(null, 'stub') extends GeminiClient
    {
        private int $calls = 0;

        public function configured(): bool
        {
            return true;
        }

        public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
        {
            $parts = $this->calls++ === 0
                ? [['functionCall' => ['name' => 'give_user_role', 'args' => ['user' => 'Jon Doe', 'role' => 'Leave Approver']]]]
                : [['text' => 'Okay.']];

            return ['candidates' => [['content' => ['parts' => $parts]]]];
        }
    });
    app()->forgetInstance(Assistant::class);

    $card = $this->postJson(route('assistant'), ['message' => 'give jon doe the leave approver role'])->assertOk()->json('message.actions.0');

    expect($card['kind'])->toBe('confirm')
        ->and($card['meta'][0])->toBe('Jon Doe would gain 1 permission: View leave requests & balances.')
        ->and($jon->fresh()->roles)->toHaveCount(0);

    $this->postJson(route('assistant.actions.confirm'), ['token' => $card['confirmation']['token']])->assertOk()->assertJsonPath('state', 'confirmed');

    expect($jon->fresh()->roles->pluck('name')->all())->toBe(['leave-approver']);
});
