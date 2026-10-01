<?php

namespace App\Services\Assistant\Modules;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use App\Support\EmployeeInvitations;
use App\Support\Setup\JoinCodeSettings;
use App\Support\Tenancy;
use App\Support\WorkspaceJoin;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Workspace Access capability (ADR 0059): who can sign in to the app as which
 * employee — the Employees → App access screen. Invitations, the requests of
 * people who joined with the company code, and whether that code works.
 *
 * Every write here lets somebody in, so every write but revoking an invitation
 * waits for the user's Confirm (ADR 0049), and each goes through the screen's
 * own classes ({@see EmployeeInvitations}, {@see WorkspaceJoin},
 * {@see JoinCodeSettings}). Two further walls:
 *
 * - **An invitation goes to the address on the 201 file only.** The screen may
 *   send it elsewhere; the assistant may not — whoever redeems it becomes that
 *   employee, and a prompt-injected model redirecting it would hand the record
 *   to a stranger.
 * - **The join code itself is never read out.** Chat can say whether joining by
 *   code is on, switch it, or replace the code; the code is shown only on the
 *   screen, so it never sits in a transcript or with the model provider.
 *
 * Needs `employees.invite`; the join code's switches need
 * `setup.company.manage`, as on the screen.
 */
class WorkspaceAccessModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const CHANNEL = ' via assistant';

    /** How many rows a list returns at most. */
    private const MAX_ROWS = 15;

    public function __construct(private readonly JoinCodeSettings $joinCode) {}

    public function key(): string
    {
        return 'workspace-access';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('employees.invite');
    }

    protected function toolMap(): array
    {
        return [
            'find_join_requests' => 'findRequests',
            'find_app_invitations' => 'findInvitations',
            'list_employees_without_app' => 'withoutApp',
            'get_join_code_status' => 'joinCodeStatus',
            'approve_join_request' => 'approve',
            'decline_join_request' => 'decline',
            'invite_to_app' => 'invite',
            'revoke_app_invitation' => 'revoke',
            'set_join_code' => 'setJoinCode',
        ];
    }

    protected function permissionMap(): array
    {
        return ['set_join_code' => 'setup.company.manage'];
    }

    protected function confirmTools(): array
    {
        return ['approve_join_request', 'decline_join_request', 'invite_to_app', 'set_join_code'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'employees.invite';

        if ($user->cannot('employees.invite') || $user->cannot($permission)) {
            return $this->denied('manage app access');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        APP ACCESS — who can sign in to the SYNAPSE app as which employee. find_join_requests (people who asked to join with the company code), find_app_invitations (sent, not yet used), list_employees_without_app, get_join_code_status.
        - approve_join_request links a request to a roster line (the employee they are); decline_join_request turns it down; invite_to_app emails an invitation to the address on the employee's file; set_join_code turns joining by code on or off or replaces the code. All of these wait for the user's confirmation; revoke_app_invitation runs directly.
        - The join code itself is never shown in chat: point to Employees → App access. An invitation is never sent to an address other than the one on file.
        TXT;
    }

    public function tools(User $user): array
    {
        $person = ['type' => 'STRING', 'description' => 'The person who asked, by name or email.'];
        $employee = ['type' => 'STRING', 'description' => 'The employee, by full name or employee number.'];

        return $this->permitted($user, [
            ['name' => 'find_join_requests', 'description' => 'Pending requests from people who joined with the company code.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'find_app_invitations', 'description' => 'App invitations sent and not yet used.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'list_employees_without_app', 'description' => 'Employees nobody signs in as yet, and whether each has an invitation.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'get_join_code_status', 'description' => 'Whether people can ask to join with the company code (the code itself is on the App access screen).', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'approve_join_request', 'description' => 'Approve a join request, linking the person to the employee they are.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['person' => $person, 'employee' => $employee], 'required' => ['person', 'employee']]],
            ['name' => 'decline_join_request', 'description' => 'Decline a join request, optionally saying why.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['person' => $person, 'reason' => ['type' => 'STRING']], 'required' => ['person']]],
            ['name' => 'invite_to_app', 'description' => 'Email an employee an invitation to the app, at the address on their file.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee], 'required' => ['employee']]],
            ['name' => 'revoke_app_invitation', 'description' => "Withdraw an employee's unused invitation.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee], 'required' => ['employee']]],
            ['name' => 'set_join_code', 'description' => 'Turn joining by company code on or off, or replace the code.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['enabled' => ['type' => 'BOOLEAN'], 'replace' => ['type' => 'BOOLEAN', 'description' => 'Generate a new code; the old one stops working.']]]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['join request', 'join requests', 'join code', 'app access', 'invitation', 'invitations', 'invite', 'mobile app', 'sign up'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('employees.invite') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $organization = app(Tenancy::class)->organization();

        return ContextSection::of('App access', [
            OrganizationJoinRequest::query()->pending()->count().' pending join requests; '
                .EmployeeInvitation::query()->outstanding()->count().' invitations not yet used; '
                .Employee::query()->whereNull('user_id')->count().' employees nobody signs in as.',
            'Joining by company code is '.($organization?->join_code_enabled ? 'on' : 'off').'.',
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        return match ($tool) {
            'approve_join_request' => (function () use ($args): ?string {
                [$request] = $this->locateRequest((string) ($args['person'] ?? ''));
                [$employee] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

                return $request === null || $employee === null ? null
                    : "{$request->user?->full_name} ({$request->user?->email}) would sign in as {$employee->full_name}, with the Staff role, and be told they were approved.";
            })(),
            'decline_join_request' => 'They would be told their request was declined'.(filled($args['reason'] ?? null) ? ', with your reason.' : '.'),
            'invite_to_app' => (function () use ($args): ?string {
                [$employee] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

                return $employee === null || blank($employee->email) ? null
                    : "An invitation goes to {$employee->email}, the address on their file. Whoever uses it signs in as {$employee->full_name}; it expires in ".EmployeeInvitations::EXPIRES_AFTER_DAYS.' days, and any earlier one for them stops working.';
            })(),
            'set_join_code' => ($args['replace'] ?? false) === true
                ? 'The current join code would stop working at once; people would need the new one from Employees → App access.'
                : (($args['enabled'] ?? null) === true
                    ? 'Anyone with the code could ask to join; each request still needs approving here.'
                    : 'Nobody could ask to join with the code; invite people individually instead.'),
            default => null,
        };
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findRequests(User $user, array $args): ToolResult
    {
        $cards = OrganizationJoinRequest::query()->pending()->with('user')->oldest('id')->limit(self::MAX_ROWS)->get()
            ->filter(fn (OrganizationJoinRequest $r): bool => $r->user !== null)
            ->map(fn (OrganizationJoinRequest $r): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: 'Pending',
                title: UntrustedText::clean($r->user->full_name, 120) ?? 'Someone',
                subtitle: (string) $r->user->email,
                meta: ['Asked '.$r->created_at?->diffForHumans()],
            ))
            ->values()
            ->all();

        return ToolResult::found('Listed join requests', $cards === [] ? 'None pending' : count($cards).' pending', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findInvitations(User $user, array $args): ToolResult
    {
        $cards = EmployeeInvitation::query()->outstanding()->with(['employee', 'inviter'])->latest('id')->limit(self::MAX_ROWS)->get()
            ->filter(fn (EmployeeInvitation $i): bool => $i->employee !== null)
            ->map(fn (EmployeeInvitation $i): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: 'Invited',
                title: $i->employee->full_name,
                subtitle: (string) $i->email,
                meta: [
                    'Sent '.$i->created_at?->diffForHumans().($i->inviter ? ' by '.UntrustedText::clean($i->inviter->full_name, 80) : ''),
                    $i->expires_at !== null ? 'Expires '.$i->expires_at->diffForHumans() : null,
                ],
                id: $i->employee_id,
            ))
            ->values()
            ->all();

        return ToolResult::found('Listed app invitations', $cards === [] ? 'None outstanding' : count($cards).' outstanding', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function withoutApp(User $user, array $args): ToolResult
    {
        $query = Employee::query()->whereNull('user_id')->with('invitations');
        $total = (clone $query)->count();

        $cards = $query->orderBy('last_name')->orderBy('first_name')->limit(self::MAX_ROWS)->get()
            ->map(fn (Employee $e): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: $e->appAccess() === 'invited' ? 'Invited' : 'No access',
                title: $e->full_name,
                subtitle: $e->employee_no,
                meta: [blank($e->email) ? 'No email on file — an invitation cannot be sent' : null],
                id: $e->id,
            ))
            ->all();

        return ToolResult::found('Listed employees without the app', $total === 0 ? 'Everyone has access' : ($total > count($cards) ? "{$total}; the first ".count($cards).' shown' : "{$total}"), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function joinCodeStatus(User $user, array $args): ToolResult
    {
        $enabled = (bool) app(Tenancy::class)->organization()?->join_code_enabled;

        return ToolResult::found('Checked the join code', null, [$this->card(
            kind: 'insight',
            tone: 'info',
            badge: $enabled ? 'On' : 'Off',
            title: $enabled ? 'People can ask to join with the company code' : 'Joining by company code is off',
            subtitle: 'The code is shown on Employees → App access (/employees/access)',
            meta: [$enabled ? 'Each request still needs approving.' : 'People join by invitation only.'],
        )]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function approve(User $user, array $args): ToolResult
    {
        [$request, $error] = $this->locateRequest((string) ($args['person'] ?? ''));

        if ($request === null) {
            return ToolResult::error('Approved the request', $error);
        }

        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Approved the request', $error);
        }

        try {
            WorkspaceJoin::approve($request, $employee, $user, self::CHANNEL);
        } catch (RuntimeException $e) {
            return ToolResult::error('Approved the request', $e->getMessage());
        }

        return ToolResult::ok("Approved {$request->user?->full_name}", "They now sign in as {$employee->full_name}.");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function decline(User $user, array $args): ToolResult
    {
        [$request, $error] = $this->locateRequest((string) ($args['person'] ?? ''));

        if ($request === null) {
            return ToolResult::error('Declined the request', $error);
        }

        $reason = filled($args['reason'] ?? null) ? Str::limit(trim((string) $args['reason']), 255, '') : null;

        try {
            WorkspaceJoin::decline($request, $user, $reason, self::CHANNEL);
        } catch (RuntimeException $e) {
            return ToolResult::error('Declined the request', $e->getMessage());
        }

        return ToolResult::ok("Declined {$request->user?->full_name}'s request", 'They were told.');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function invite(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Sent the invitation', $error);
        }

        try {
            // The address on file, never one from the conversation.
            $invitation = EmployeeInvitations::invite($employee, $user, null, self::CHANNEL);
        } catch (RuntimeException $e) {
            return ToolResult::error('Sent the invitation', $e->getMessage());
        }

        return ToolResult::ok("Invited {$employee->full_name} to the app", "The invitation went to {$invitation->email}.");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function revoke(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Revoked the invitation', $error);
        }

        return EmployeeInvitations::revoke($employee, self::CHANNEL)
            ? ToolResult::ok("Revoked {$employee->full_name}'s invitation", 'It no longer works.')
            : ToolResult::error('Revoked the invitation', "{$employee->full_name} has no invitation outstanding.");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setJoinCode(User $user, array $args): ToolResult
    {
        $organization = app(Tenancy::class)->organization();
        $done = [];

        if (($args['replace'] ?? false) === true) {
            $this->joinCode->rotate($organization, self::CHANNEL);
            $done[] = 'Generated a new join code — it is on Employees → App access; the old one no longer works.';
        }

        if (is_bool($args['enabled'] ?? null)) {
            $changed = $this->joinCode->setEnabled($organization, $args['enabled'], self::CHANNEL);
            $done[] = $changed
                ? ($args['enabled'] ? 'Joining by code is now on.' : 'Joining by code is now off.')
                : 'Joining by code was already '.($args['enabled'] ? 'on.' : 'off.');
        }

        return $done === []
            ? ToolResult::error('Changed the join code', 'Say whether to turn joining by code on or off, or to replace the code.')
            : ToolResult::ok('Changed the join code', implode(' ', $done));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Exactly one pending join request, by the requester's name or email.
     *
     * @return array{0: OrganizationJoinRequest|null, 1: string}
     */
    private function locateRequest(string $needle): array
    {
        $needle = Str::lower(trim($needle));

        if ($needle === '') {
            return [null, 'Say whose request.'];
        }

        $needle = preg_replace('/\s+/', ' ', $needle) ?? $needle;
        $exactly = fn (OrganizationJoinRequest $r): bool => in_array($needle, [
            Str::lower((string) $r->user->email),
            Str::lower((string) $r->user->full_name),
            Str::lower(trim($r->user->first_name.' '.$r->user->last_name)),
        ], true);

        $matches = OrganizationJoinRequest::query()->pending()->with('user')->get()
            ->filter(fn (OrganizationJoinRequest $r): bool => $r->user !== null && (
                $exactly($r)
                || collect(explode(' ', $needle))->every(fn (string $w): bool => str_contains(Str::lower((string) $r->user->full_name), $w))
            ))
            ->values();

        // "Pia Pending" is Pia Pending, even with a Pia Pendington also waiting.
        if ($matches->count() > 1 && $matches->filter($exactly)->count() === 1) {
            $matches = $matches->filter($exactly)->values();
        }

        return match ($matches->count()) {
            0 => [null, 'No pending join request is from “'.Str::limit($needle, 60).'”.'],
            1 => [$matches->first(), ''],
            default => [null, 'More than one pending request matches “'.Str::limit($needle, 60).'”. Use their email.'],
        };
    }
}
