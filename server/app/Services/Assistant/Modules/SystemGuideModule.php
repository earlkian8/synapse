<?php

namespace App\Services\Assistant\Modules;

use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use App\Support\Help\HelpCenter;
use App\Support\PermissionRegistry;
use App\Support\Setup\CompanySetup;
use App\Support\SystemGuide;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * System Guide capability (ADR 0059): SYSTEM itself — "how do I invite
 * someone?", "where do I set holidays?", "what can I do here?", "which
 * companies am I in?", "what is left to set up?".
 *
 * Its knowledge is {@see SystemGuide}, a catalogue of the real screens, always
 * read for the asker: a screen they cannot open is never described to them, so
 * the guide cannot be used to map the parts of the system they have no access
 * to. It says nothing about how SYNAPSE is built — only what it does. Every tool
 * only reads, and needs nothing beyond being signed in to this workspace
 * (setup progress needs the Company Profile's `setup.company.view`).
 */
class SystemGuideModule extends Module implements ContributesTopicContext
{
    public function key(): string
    {
        return 'guide';
    }

    public function isAvailable(User $user): bool
    {
        return true;
    }

    protected function toolMap(): array
    {
        return [
            'find_help' => 'findHelp',
            'get_my_access' => 'myAccess',
            'list_my_workspaces' => 'myWorkspaces',
            'get_setup_progress' => 'setupProgress',
        ];
    }

    protected function permissionMap(): array
    {
        return ['get_setup_progress' => 'setup.company.view'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? null;

        if ($permission !== null && $user->cannot($permission)) {
            return $this->denied('see that');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        SYSTEM GUIDE — how SYNAPSE works and where things are, for this user. find_help answers "how do I…/where is…" with the screen, its menu path and address, what the assistant can do there, and the Help Center article that explains it step by step; get_my_access says what this user can do here; list_my_workspaces lists their companies; get_setup_progress says which company setup steps are done.
        - Describe only screens the guide returns for this user. If they ask about something they cannot open, say they do not have access to it and to ask an administrator — do not describe it.
        - When find_help returns a Help Center article, link it by its address for the full steps.
        - When the assistant itself can do the task, offer to do it.
        TXT;
    }

    public function tools(User $user): array
    {
        return $this->permitted($user, [
            ['name' => 'find_help', 'description' => 'Find the screens that answer a how-to or where-is question, with menu path, address and what the assistant can do there.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['question' => ['type' => 'STRING']], 'required' => ['question']]],
            ['name' => 'get_my_access', 'description' => "This user's roles here, what they can do, and the screens they can open.", 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'list_my_workspaces', 'description' => 'The companies this user belongs to, and which one is open now.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'get_setup_progress', 'description' => 'Which company setup steps are done, skipped or still open.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'how do i', 'how can i', 'how to', 'where do i', 'where can i', 'where is', 'where are', 'what can you do',
            'what can i do', 'help me', 'my access', 'my permissions', 'my role', 'my roles', 'menu', 'navigate',
            'which page', 'what page', 'which screen', 'how does synapse', 'what is synapse',
        ];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if (! app(Tenancy::class)->check()) {
            return null;
        }

        $screens = SystemGuide::for($user)
            ->map(fn (array $s): string => $s['title'].($s['path'] !== '' ? " ({$s['path']})" : " ({$s['menu']})"))
            ->implode('; ');

        return ContextSection::of('System guide', [
            'Screens this user can open: '.$screens.'.',
            'Use find_help for the steps on any of them. The Help Center (/help) has a step-by-step article for each.',
        ]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findHelp(User $user, array $args): ToolResult
    {
        $question = trim((string) ($args['question'] ?? ''));

        if ($question === '') {
            return ToolResult::error('Looked it up', 'Say what you want to do.');
        }

        $screens = SystemGuide::search($user, $question)
            ->map(fn (array $s): array => $this->screenCard($s));

        // The manual's own answer, when an article matches every word of the
        // question — read for the same user, so it never names a screen the
        // guide above would not.
        $found = HelpCenter::search($user, $question, 2);
        $articles = $found['partial'] ? collect() : $found['results']
            ->map(fn (array $article): array => $this->articleCard($article));

        $cards = $screens->concat($articles)->values()->all();

        return ToolResult::found(
            'Looked it up',
            $cards === [] ? 'Nothing you have access to matches that. It may need a permission you do not have — ask an administrator.' : null,
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function myAccess(User $user, array $args): ToolResult
    {
        $roles = $user->roles()->orderBy('label')->pluck('label');
        $held = $user->permissionNames();
        $employee = $user->employee()->first(['id', 'user_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no']);

        $groups = $user->isSuperAdmin()
            ? ['Everything — the HR Manager role holds every permission']
            : collect(PermissionRegistry::GROUPS)
                ->map(fn (array $permissions, string $group): array => [$group, $held->intersect(array_keys($permissions))->count(), count($permissions)])
                ->filter(fn (array $row): bool => $row[1] > 0)
                ->map(fn (array $row): string => "{$row[0]} {$row[1]}/{$row[2]}")
                ->values()
                ->all();

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: 'Your access',
            title: (string) $user->full_name,
            subtitle: 'Roles here: '.($roles->isEmpty() ? 'none' : $roles->implode(', ')),
            meta: [
                'Can: '.($groups === [] ? 'nothing yet — ask an administrator for a role' : implode(', ', $groups)),
                'Screens: '.SystemGuide::for($user)->pluck('title')->implode(', '),
                $employee !== null ? "Linked to your employee record ({$employee->employee_no})" : 'Not linked to an employee record here',
                'The assistant acts only within this access, and never sees pay, government ID numbers, bank details, home addresses, birth dates or passwords.',
            ],
        );

        return ToolResult::found('Read your access', null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function myWorkspaces(User $user, array $args): ToolResult
    {
        $current = app(Tenancy::class)->id();

        $cards = $user->memberships()->orderBy('organizations.name')->get()
            ->map(fn (Organization $org): array => $this->card(
                kind: 'find',
                tone: $org->id === $current ? 'info' : 'neutral',
                badge: $org->id === $current ? 'Open now' : 'Workspace',
                title: UntrustedText::clean($org->name, 120) ?? 'Workspace',
                meta: [(bool) $org->pivot->is_default ? 'Where you land when you sign in' : null],
            ))
            ->all();

        return ToolResult::found('Listed your workspaces', count($cards).' '.Str::plural('workspace', count($cards)).' — switch from the account menu.', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setupProgress(User $user, array $args): ToolResult
    {
        $organization = app(Tenancy::class)->organization();
        $statuses = CompanySetup::statuses($organization);
        $configured = CompanySetup::configured($organization);
        $open = collect($statuses)->filter(fn (string $s): bool => $s === CompanySetup::PENDING);

        $card = $this->card(
            kind: 'insight',
            tone: $open->isEmpty() ? 'positive' : 'info',
            badge: $organization->hasFinishedSetup() ? 'Finished' : 'In progress',
            title: (count($statuses) - $open->count()).' of '.count($statuses).' setup steps answered',
            subtitle: 'Setup Guide: /setup/wizard',
            meta: collect($statuses)->map(fn (string $status, string $step): string => (SystemGuide::SETUP_STEPS[$step] ?? $step).': '.match ($status) {
                CompanySetup::DONE => 'done',
                CompanySetup::SKIPPED => 'skipped'.($configured[$step] ?? false ? ' (set up on its own screen)' : ''),
                default => ($configured[$step] ?? false) ? 'open, though something is already set up' : 'not started',
            })->values()->all(),
        );

        return ToolResult::found('Read the setup progress', null, [$card]);
    }

    /**
     * A Help Center article that answers the question (ADR 0062).
     *
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    private function articleCard(array $article): array
    {
        return $this->card(
            kind: 'insight',
            tone: 'neutral',
            badge: 'Help Center',
            title: $article['title'],
            subtitle: $article['href'],
            meta: [
                $article['summary'],
                'Step by step in the Help Center: '.$article['href'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return array<string, mixed>
     */
    private function screenCard(array $screen): array
    {
        return $this->card(
            kind: 'insight',
            tone: 'info',
            badge: $screen['menu'],
            title: $screen['title'],
            subtitle: $screen['path'] !== '' ? $screen['path'] : null,
            meta: [
                $screen['about'],
                'There you can: '.implode('; ', $screen['tasks']).'.',
                'In chat: '.$screen['assistant'],
            ],
        );
    }
}
