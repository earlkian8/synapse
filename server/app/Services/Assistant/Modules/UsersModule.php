<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Queries\UserStatistics;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\PermissionRegistry;
use App\Support\Roles\GrantRules;
use App\Support\Roles\RoleException;
use App\Support\Roles\RoleWorkflow;
use App\Support\Tenancy;
use App\Support\Users\UserAccountException;
use App\Support\Users\UserAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * User Management capability (ADR 0057): the sign-in accounts of this
 * workspace — who has one, whether they can sign in, which roles they hold here
 * and so what they can do.
 *
 * **Reading** answers "who can sign in?", "who hasn't verified their email?",
 * "what can Maria do?", "who can approve leave?". **Doing** adds somebody,
 * corrects their name, changes their sign-in email, activates or deactivates
 * them, gives or takes a role and archives them — through {@see UserAccounts}
 * and {@see RoleWorkflow}, the screen's own path, against the screen's own
 * rules ({@see StoreUserRequest}, {@see UpdateUserRequest}).
 *
 * Everything that changes who can get in or what they can do waits for the
 * user's Confirm (ADR 0049), and the card says what it would change. The rules
 * that keep one company out of another's accounts hold here as on the screen:
 * an account another workspace shares is its holder's, nobody changes the
 * account of someone with more access, and nobody grants access they lack.
 *
 * **Never here:** passwords (one would be sent to the model and kept in the
 * conversation — the screen resets them), photos, importing and exporting, and
 * permanent deletion and restoring, which are the Trash Bin's.
 */
class UsersModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const CHANNEL = ' via assistant';

    /** How many accounts a list returns at most. */
    private const MAX_ROWS = 15;

    /** @var list<string> */
    private const STATUSES = ['active', 'inactive', 'unverified', 'archived'];

    /** The fields of a person's name, as the tool takes them. */
    private const NAME = ['first_name', 'middle_name', 'last_name', 'suffix'];

    public function __construct(
        private readonly UserAccounts $accounts,
        private readonly RoleWorkflow $roles,
        private readonly UserStatistics $statistics,
    ) {}

    public function key(): string
    {
        return 'users';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('users.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_users' => 'findUsers',
            'get_user' => 'getUser',
            'create_user' => 'createUser',
            'update_user' => 'updateUser',
            'change_user_email' => 'changeEmail',
            'set_user_active' => 'setActive',
            'give_user_role' => 'giveRole',
            'take_user_role' => 'takeRole',
            'archive_user' => 'archive',
            'resend_user_verification' => 'resendVerification',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_users' => 'users.view',
            'get_user' => 'users.view',
            'create_user' => 'users.create',
            'update_user' => 'users.update',
            'change_user_email' => 'users.update',
            'set_user_active' => 'users.manage-status',
            'give_user_role' => 'roles.assign',
            'take_user_role' => 'roles.assign',
            'archive_user' => 'users.delete',
            'resend_user_verification' => 'users.update',
        ];
    }

    protected function confirmTools(): array
    {
        // Each of these changes who can get in, as whom, or what they can do.
        return ['create_user', 'change_user_email', 'set_user_active', 'give_user_role', 'take_user_role', 'archive_user'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot('users.view') || $user->cannot($this->permissionMap()[$tool])) {
            return $this->denied('do that with user accounts');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        USERS — the sign-in accounts of this workspace: who can sign in, whether their email is verified, and the roles they hold here (which decide what they can do).
        - find_users lists accounts by name/email, status (active, inactive, unverified, archived), role, or a permission they have ("who can approve leave?"). get_user reads one: roles, what they can do, last sign-in.
        - create_user adds somebody (a verification email is sent; an address that already has an account elsewhere is linked in). update_user corrects a name or phone. change_user_email, set_user_active, give_user_role, take_user_role and archive_user wait for the user's confirmation.
        - An account that also belongs to another workspace is its holder's: only its roles here can change, and archiving removes it from this workspace. Nobody can change the account of someone with more access than they have, or give access they do not hold — say so plainly when a result says it.
        - Passwords are never set here: point to the Users screen. Restoring and permanently deleting archived accounts is the trash bin's.
        TXT;
    }

    public function tools(User $user): array
    {
        $who = ['type' => 'STRING', 'description' => 'The person, by full name or email.'];
        $role = ['type' => 'STRING', 'description' => 'The role, by its label (e.g. "HR Manager").'];

        return $this->permitted($user, [
            [
                'name' => 'find_users',
                'description' => 'List accounts of this workspace, optionally by name/email, status, role, or a permission they hold.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'search' => ['type' => 'STRING'],
                        'status' => ['type' => 'STRING', 'enum' => self::STATUSES],
                        'role' => $role,
                        'can' => ['type' => 'STRING', 'description' => 'A permission, by what it lets someone do (e.g. "approve leave") or its key.'],
                    ],
                ],
            ],
            ['name' => 'get_user', 'description' => 'Read one account: status, roles here, what they can do, last sign-in, linked employee.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who], 'required' => ['user']]],
            [
                'name' => 'create_user',
                'description' => 'Add somebody to this workspace with a sign-in account; a verification email is sent.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'first_name' => ['type' => 'STRING'],
                        'middle_name' => ['type' => 'STRING'],
                        'last_name' => ['type' => 'STRING'],
                        'suffix' => ['type' => 'STRING'],
                        'email' => ['type' => 'STRING'],
                        'roles' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Role labels to give them.'],
                    ],
                    'required' => ['first_name', 'last_name', 'email'],
                ],
            ],
            [
                'name' => 'update_user',
                'description' => "Correct an account's name or phone number.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'user' => $who,
                        'first_name' => ['type' => 'STRING'],
                        'middle_name' => ['type' => 'STRING'],
                        'last_name' => ['type' => 'STRING'],
                        'suffix' => ['type' => 'STRING'],
                        'phone_number' => ['type' => 'STRING'],
                    ],
                    'required' => ['user'],
                ],
            ],
            ['name' => 'change_user_email', 'description' => 'Change the email an account signs in with; it must be verified again.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who, 'email' => ['type' => 'STRING']], 'required' => ['user', 'email']]],
            ['name' => 'set_user_active', 'description' => 'Let an account sign in (active true) or stop it (active false).', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who, 'active' => ['type' => 'BOOLEAN']], 'required' => ['user', 'active']]],
            ['name' => 'give_user_role', 'description' => 'Give somebody a role in this workspace.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who, 'role' => $role], 'required' => ['user', 'role']]],
            ['name' => 'take_user_role', 'description' => 'Take a role away from somebody in this workspace.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who, 'role' => $role], 'required' => ['user', 'role']]],
            ['name' => 'archive_user', 'description' => 'Archive an account (or remove it from this workspace when it belongs to another too).', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who], 'required' => ['user']]],
            ['name' => 'resend_user_verification', 'description' => 'Send the email-verification code again to an unverified account.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['user' => $who], 'required' => ['user']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'user account', 'user accounts', 'system user', 'system users', 'who can sign in', 'who can log in',
            'sign-in', 'unverified', 'deactivated', 'hr manager', 'hr managers', 'who has access',
        ];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('users.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $stats = $this->statistics->toArray();
        $managers = User::query()->inCurrentOrganization()->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', Role::SUPER_ADMIN))
            ->orderBy('first_name')->limit(10)->get();

        return ContextSection::of('User accounts', [
            "{$stats['total']} accounts in this workspace: {$stats['active']} active, {$stats['inactive']} inactive, {$stats['unverified']} with an unverified email; {$stats['archived']} archived.",
            'HR Managers (every permission): '.$this->catalog($managers->map(fn (User $u): string => $u->full_name)).'.',
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool === 'create_user') {
            $email = Str::lower(trim((string) ($args['email'] ?? '')));

            if ($email === '') {
                return null;
            }

            $roles = collect((array) ($args['roles'] ?? []))->filter()->implode(', ');

            // Whether the address already has an account is not said here: the
            // card would answer it for any address, without anything being done.
            return "A verification email goes to {$email}; an address that already has an account elsewhere is added to this workspace instead, and told."
                .($roles !== '' ? " Roles: {$roles}." : ' No roles yet — they can sign in but do nothing until given one.');
        }

        [$target] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return null;
        }

        return match ($tool) {
            'change_user_email' => 'They would sign in as '.Str::lower(trim((string) ($args['email'] ?? ''))).' from now on, and could use the app only after confirming it.',
            'set_user_active' => ($args['active'] ?? null) === false
                ? "{$target->full_name} would be signed out now and could not sign in until reactivated."
                : "{$target->full_name} could sign in again, with the roles they hold: {$this->roleList($target)}.",
            'give_user_role' => $this->gainLine($target, (string) ($args['role'] ?? '')),
            'take_user_role' => $this->lossLine($target, (string) ($args['role'] ?? '')),
            'archive_user' => UserAccounts::isShared($target)
                ? "{$target->full_name}'s account belongs to another workspace too: they would be removed from this one (and lose its roles); the account itself stays."
                : "{$target->full_name} would be signed out and moved to the Trash Bin, where they can be restored.",
            default => null,
        };
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findUsers(User $user, array $args): ToolResult
    {
        $status = in_array($args['status'] ?? null, self::STATUSES, true) ? (string) $args['status'] : null;
        $query = ($status === 'archived' ? User::onlyTrashed() : User::query())->inCurrentOrganization();

        match ($status) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'unverified' => $query->whereNull('email_verified_at'),
            default => null,
        };

        $filters = [];

        if (filled($args['role'] ?? null)) {
            [$role, $error] = $this->resolveRole((string) $args['role']);

            if ($role === null) {
                return ToolResult::error('Looked up the role', $error);
            }

            $query->whereHas('roles', fn (Builder $q) => $q->whereKey($role->id));
            $filters[] = "with the {$role->label} role";
        }

        if (filled($args['can'] ?? null)) {
            [$permissions, $error] = PermissionRegistry::lookup((string) $args['can']);

            if ($permissions === []) {
                return ToolResult::error('Looked up the permission', $error);
            }

            // HR Managers hold every permission by bypassing the gate, not by a
            // grant. A whole group means any permission in it.
            $query->whereHas('roles', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', Role::SUPER_ADMIN)
                ->orWhereHas('permissions', fn (Builder $p) => $p->whereIn('name', $permissions))));
            $filters[] = count($permissions) === 1
                ? 'who can '.Str::lcfirst(GrantRules::labels($permissions))
                : 'with any of: '.GrantRules::labels($permissions, 3);
        }

        if (filled($args['search'] ?? null)) {
            $this->matchByTokens($query, (string) $args['search']);
        }

        $total = (clone $query)->count();
        $cards = $query->with('roles:id,name,label')->orderBy('first_name')->orderBy('last_name')->limit(self::MAX_ROWS)->get()
            ->map(fn (User $u): array => $this->userCard($u, 'find', 'neutral'))
            ->all();

        $label = 'Listed '.($status !== null ? "{$status} " : '').'accounts'.($filters !== [] ? ' '.implode(' and ', $filters) : '');

        return ToolResult::found($label, $total === 0 ? 'None' : ($total > count($cards) ? "{$total} found; the first ".count($cards).' shown' : "{$total} found"), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getUser(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''), archived: true);

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        $target->load('roles.permissions:id,name', 'employee:id,user_id,employee_no');
        $card = $this->userCard($target, 'insight', 'info');

        $card['meta'] = array_values(array_filter([
            ...$card['meta'],
            'Can: '.$this->accessLine($target),
            $target->employee !== null ? "Linked to employee {$target->employee->employee_no}" : 'Not linked to an employee record',
            $target->two_factor_secret !== null ? 'Two-step sign-in on' : 'Two-step sign-in off',
            $target->trashed() ? 'Archived — restore it from the trash bin' : null,
            UserAccounts::isShared($target) ? 'Also belongs to another workspace: their details and sign-in are theirs; only their roles here can change' : null,
            ! $target->trashed() && GrantRules::outranks($target, $user) ? "Has access you don't, so you can't change their account" : null,
        ]));

        return ToolResult::found("Read {$target->full_name}", null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createUser(User $user, array $args): ToolResult
    {
        $profile = $this->profile($args, [...self::NAME, 'email']);
        $profile['email'] = Str::lower((string) ($profile['email'] ?? ''));

        $problem = $this->invalid($profile, Arr::only(StoreUserRequest::rulesFor(), [...self::NAME, 'email']));

        if ($problem !== null) {
            return ToolResult::error('Added the user', $problem);
        }

        $roleIds = null;

        if (filled($args['roles'] ?? null)) {
            if ($user->cannot('roles.assign')) {
                return ToolResult::error('Added the user', "You can't assign roles. Leave them out, or ask someone who can.");
            }

            $roleIds = [];

            foreach ((array) $args['roles'] as $name) {
                [$role, $error] = $this->resolveRole((string) $name);

                if ($role === null) {
                    return ToolResult::error('Added the user', $error);
                }

                $roleIds[] = $role->id;
            }
        }

        try {
            $result = $this->accounts->create([...$profile, 'is_active' => true], $roleIds, $user, null, self::CHANNEL);
        } catch (UserAccountException|RoleException $e) {
            return ToolResult::error('Added the user', $e->getMessage());
        }

        $added = $result['user']->load('roles:id,name,label');

        return ToolResult::ok(
            "Added {$added->full_name}",
            match (true) {
                $result['linked'] => "{$added->email} already had an account and was added to this workspace.",
                $result['verification_sent'] => "A verification email was sent to {$added->email}.",
                default => 'The verification email could not be sent — resend it from their actions.',
            },
            $this->userCard($added, 'add', 'positive', 'Added'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateUser(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        $changes = $this->profile($args, [...self::NAME, 'phone_number']);

        if ($changes === []) {
            return ToolResult::error('Updated the account', 'Say what to change: a part of their name, or their phone number.');
        }

        $merged = [...$target->only([...self::NAME, 'phone_number']), ...$changes];
        $problem = $this->invalid($merged, Arr::only(UpdateUserRequest::rulesFor($target), [...self::NAME, 'phone_number']));

        if ($problem !== null) {
            return ToolResult::error('Updated the account', $problem);
        }

        try {
            $this->accounts->update($target, $changes, null, $user, channel: self::CHANNEL);
        } catch (UserAccountException $e) {
            return ToolResult::error('Updated the account', $e->getMessage());
        }

        return ToolResult::ok("Updated {$target->full_name}", null, $this->userCard($target->load('roles:id,name,label'), 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function changeEmail(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        $email = Str::lower(trim((string) ($args['email'] ?? '')));
        $problem = $this->invalid(['email' => $email], Arr::only(UpdateUserRequest::rulesFor($target), ['email']));

        if ($problem !== null) {
            return ToolResult::error('Changed the email', $problem);
        }

        if ($email === Str::lower((string) $target->email)) {
            return ToolResult::error('Changed the email', "{$target->full_name} already signs in as {$email}.");
        }

        try {
            $this->accounts->update($target, ['email' => $email], null, $user, channel: self::CHANNEL);
        } catch (UserAccountException $e) {
            return ToolResult::error('Changed the email', $e->getMessage());
        }

        return ToolResult::ok("Changed {$target->full_name}'s email", "A verification email was sent to {$email}.", $this->userCard($target->load('roles:id,name,label'), 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setActive(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        if (! is_bool($args['active'] ?? null)) {
            return ToolResult::error('Changed the account', 'Say whether they should be able to sign in.');
        }

        try {
            $this->accounts->setActive($target, $args['active'], $user, self::CHANNEL);
        } catch (UserAccountException $e) {
            return ToolResult::error('Changed the account', $e->getMessage());
        }

        return $args['active']
            ? ToolResult::ok("Activated {$target->full_name}", 'They can sign in again.', $this->userCard($target->load('roles:id,name,label'), 'edit', 'positive', 'Activated'))
            : ToolResult::ok("Deactivated {$target->full_name}", 'They are signed out and cannot sign in until reactivated.', $this->userCard($target->load('roles:id,name,label'), 'edit', 'warning', 'Deactivated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function giveRole(User $user, array $args): ToolResult
    {
        [$target, $role, $error] = $this->personAndRole($args);

        if ($error !== null) {
            return ToolResult::error('Gave the role', $error);
        }

        try {
            $given = $this->roles->give($target, $role, $user, self::CHANNEL);
        } catch (RoleException $e) {
            return ToolResult::error('Gave the role', $e->getMessage());
        }

        if (! $given) {
            return ToolResult::error('Gave the role', "{$target->full_name} already has the {$role->label} role.");
        }

        return ToolResult::ok("Gave {$target->full_name} the {$role->label} role", 'They were told their access changed.', $this->userCard($target->load('roles:id,name,label'), 'edit', 'positive', 'Role given'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function takeRole(User $user, array $args): ToolResult
    {
        [$target, $role, $error] = $this->personAndRole($args);

        if ($error !== null) {
            return ToolResult::error('Took the role', $error);
        }

        try {
            $taken = $this->roles->take($target, $role, $user, self::CHANNEL);
        } catch (RoleException $e) {
            return ToolResult::error('Took the role', $e->getMessage());
        }

        if (! $taken) {
            return ToolResult::error('Took the role', "{$target->full_name} does not have the {$role->label} role.");
        }

        return ToolResult::ok("Took the {$role->label} role from {$target->full_name}", null, $this->userCard($target->load('roles:id,name,label'), 'edit', 'warning', 'Role taken'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archive(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        try {
            $outcome = $this->accounts->archive($target, $user, self::CHANNEL);
        } catch (UserAccountException $e) {
            return ToolResult::error('Archived the account', $e->getMessage());
        }

        return $outcome === 'removed'
            ? ToolResult::ok("Removed {$target->full_name} from this workspace", 'Their account belongs to another workspace too, so it stays.', $this->userCard($target, 'remove', 'warning', 'Removed'))
            : ToolResult::ok("Archived {$target->full_name}", 'They are in the trash bin and can be restored.', $this->userCard($target, 'remove', 'warning', 'Archived'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resendVerification(User $user, array $args): ToolResult
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return ToolResult::error('Looked up the account', $error);
        }

        try {
            $this->accounts->resendVerification($target, self::CHANNEL);
        } catch (UserAccountException $e) {
            return ToolResult::error('Sent the verification email', $e->getMessage());
        }

        return ToolResult::ok("Sent {$target->full_name} a new verification code", "It went to {$target->email}.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The given profile fields, trimmed, with blanks dropped.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function profile(array $args, array $fields): array
    {
        return collect(Arr::only($args, $fields))
            ->map(fn (mixed $value): string => trim(is_scalar($value) ? (string) $value : ''))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: User|null, 1: Role|null, 2: string|null}
     */
    private function personAndRole(array $args): array
    {
        [$target, $error] = $this->resolveAccount((string) ($args['user'] ?? ''));

        if ($target === null) {
            return [null, null, $error];
        }

        [$role, $error] = $this->resolveRole((string) ($args['role'] ?? ''));

        return [$target, $role, $role === null ? $error : null];
    }

    private function roleList(User $target): string
    {
        return $target->roles()->pluck('label')->implode(', ') ?: 'none';
    }

    /**
     * What a person can do, by permission group: "everything" for an HR
     * Manager, else "Leave Management 3/3, Attendance 1/3".
     */
    private function accessLine(User $target): string
    {
        if ($target->isSuperAdmin()) {
            return 'everything (HR Manager)';
        }

        $held = $target->permissionNames();

        if ($held->isEmpty()) {
            return 'nothing yet — no role grants any permission';
        }

        return collect(PermissionRegistry::GROUPS)
            ->map(fn (array $permissions, string $group): array => [$group, $held->intersect(array_keys($permissions))->count(), count($permissions)])
            ->filter(fn (array $row): bool => $row[1] > 0)
            ->map(fn (array $row): string => "{$row[0]} {$row[1]}/{$row[2]}")
            ->implode(', ');
    }

    private function gainLine(User $target, string $roleName): ?string
    {
        [$role] = $this->resolveRole($roleName);

        if ($role === null) {
            return null;
        }

        if ($role->isSuperAdmin()) {
            return "{$target->full_name} would hold every permission in this workspace.";
        }

        $new = $this->newPermissions($target, $role->permissions()->pluck('name'));

        return $new->isEmpty()
            ? "{$target->full_name} already has everything the {$role->label} role grants."
            : "{$target->full_name} would gain ".$new->count().' '.Str::plural('permission', $new->count()).': '.GrantRules::labels($new).'.';
    }

    private function lossLine(User $target, string $roleName): ?string
    {
        [$role] = $this->resolveRole($roleName);

        if ($role === null) {
            return null;
        }

        $kept = $target->roles()->whereKeyNot($role->id)->with('permissions:id,name')->get();

        if ($kept->contains(fn (Role $r): bool => $r->isSuperAdmin())) {
            return "{$target->full_name} keeps every permission through another role.";
        }

        $keptNames = $kept->flatMap(fn (Role $r): Collection => $r->permissions->pluck('name'));
        $lost = $role->isSuperAdmin()
            ? collect(PermissionRegistry::names())->diff($keptNames)->values()
            : $role->permissions()->pluck('name')->diff($keptNames)->values();

        return $lost->isEmpty()
            ? "{$target->full_name} keeps everything through their other roles."
            : "{$target->full_name} would lose ".$lost->count().' '.Str::plural('permission', $lost->count()).': '.GrantRules::labels($lost).'.';
    }

    /**
     * @param  Collection<int, string>  $permissions
     * @return Collection<int, string>
     */
    private function newPermissions(User $target, Collection $permissions): Collection
    {
        return $target->isSuperAdmin() ? collect() : $permissions->diff($target->permissionNames())->values();
    }

    /**
     * @param  list<string>  $meta
     * @return array<string, mixed>
     */
    private function userCard(User $u, string $kind, string $tone, ?string $badge = null, array $meta = []): array
    {
        $status = match (true) {
            $u->trashed() => 'Archived',
            ! $u->is_active => 'Inactive',
            $u->email_verified_at === null => 'Unverified',
            default => 'Active',
        };

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge ?? $status,
            title: (string) $u->full_name,
            subtitle: (string) $u->email,
            meta: [
                $badge !== null ? $status : null,
                'Roles: '.($u->roles->pluck('label')->implode(', ') ?: 'none'),
                $u->last_login_at !== null ? 'Last signed in '.$u->last_login_at->diffForHumans() : 'Never signed in',
                ...$meta,
            ],
            avatar: [
                'name' => (string) $u->full_name,
                'initials' => mb_strtoupper(mb_substr((string) $u->first_name, 0, 1).mb_substr((string) $u->last_name, 0, 1)),
                'photo' => $u->avatar,
            ],
            id: $u->id,
        );
    }
}
