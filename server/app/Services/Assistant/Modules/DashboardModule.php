<?php

namespace App\Services\Assistant\Modules;

use App\Models\ActivityLog;
use App\Models\User;
use App\Queries\DashboardOverview;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dashboard capability: the workspace at a glance, as the signed-in user may see
 * it — "how are we doing today?", "what needs my attention?", "anything coming
 * up?", "what changed lately?".
 *
 * Every figure comes from {@see DashboardOverview}, the class the home dashboard
 * itself is drawn from, so a number quoted in chat is the number on the screen —
 * and so is the gating: a block the dashboard would leave out for this user
 * (headcount without `employees.view`, the audit trail without
 * `activity-logs.view`) is left out here too. Nothing in this module changes
 * anything.
 *
 * It answers in two ways. As a **topic** ({@see ContributesTopicContext}), a
 * question about the workspace in general is answered from a brief read before
 * the model is called — one request, no tool. As **tools**, the model can ask
 * for one block in detail, the action queue, the upcoming calendar or the recent
 * audit trail when the question is narrower than the brief.
 */
class DashboardModule extends Module implements ContributesTopicContext
{
    /** The dashboard's blocks and the permission each needs — its own rules. */
    private const BLOCKS = [
        'workforce' => ['employees.view'],
        'attendance' => ['attendance.view'],
        'leave' => ['leave.view', 'leave.manage'],
        'recruitment' => ['recruitment.view'],
        'onboarding' => ['onboarding.view'],
        'offboarding' => ['offboarding.view'],
        'events' => ['events.view'],
        'activity' => ['activity-logs.view'],
    ];

    /** How many audit entries or events a read returns at most. */
    private const MAX_ROWS = 15;

    public function __construct(private readonly DashboardOverview $overview) {}

    public function key(): string
    {
        return 'dashboard';
    }

    /**
     * Available to anybody the dashboard shows at least one block to — a regular
     * employee, who sees none, is not offered an empty capability.
     */
    public function isAvailable(User $user): bool
    {
        foreach (self::BLOCKS as $permissions) {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function toolMap(): array
    {
        return [
            'get_workspace_overview' => 'overview',
            'get_attention_queue' => 'attention',
            'get_recent_activity' => 'activity',
            'get_attendance_trend' => 'trend',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'get_recent_activity' => 'activity-logs.view',
            'get_attendance_trend' => 'attendance.view',
        ];
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
        $blocks = implode(', ', array_keys(array_filter(
            self::BLOCKS,
            fn (array $permissions): bool => collect($permissions)->contains(fn (string $p): bool => $user->can($p)),
        )));

        return <<<TXT
        DASHBOARD — the workspace at a glance, exactly as this user's home dashboard shows it (visible blocks: {$blocks}). Read-only.
        - A general question ("how are we doing today?", "catch me up") is usually answered by the retrieved workspace context already — do not call a tool for what it contains.
        - get_workspace_overview reads one block (or all) in detail; get_attention_queue lists what currently needs this user's action; get_recent_activity (the audit trail) and get_attendance_trend (the last 14 days) are there when asked for specifically. Events have their own tools.
        TXT;
    }

    public function tools(User $user): array
    {
        $focus = array_values(array_filter(
            ['everything', 'workforce', 'attendance', 'leave', 'recruitment', 'onboarding', 'offboarding'],
            fn (string $block): bool => $block === 'everything' || $this->sees($user, $block),
        ));

        return $this->permitted($user, [
            [
                'name' => 'get_workspace_overview',
                'description' => "The dashboard's headline numbers: workforce, today's attendance, leave, recruitment, onboarding and offboarding — only the blocks this user may see.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'focus' => ['type' => 'STRING', 'enum' => $focus, 'description' => 'One block, or everything.'],
                    ],
                ],
            ],
            [
                'name' => 'get_attention_queue',
                'description' => 'What currently needs this user\'s action across modules: leave to review, overdue onboarding tasks, flagged clearance items, upcoming interviews.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_recent_activity',
                'description' => 'The most recent entries in the audit trail — who changed what.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'module' => ['type' => 'STRING', 'description' => 'Only this area, e.g. leave, performance, onboarding, recruitment, attendance, employees.'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'How many entries (1–15). Defaults to 8.'],
                    ],
                ],
            ],
            [
                'name' => 'get_attendance_trend',
                'description' => 'How many people were present each day over the last 14 days.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'dashboard', 'overview', 'today', 'attention', 'pending', 'happening', 'catch me up', 'brief me',
            'headcount', 'workforce', 'how are we', 'how is the company', "how's the company", 'what needs',
            'to-do', 'to do', 'to review', 'this week', 'coming up', 'upcoming', 'kumusta tayo', 'ngayon',
        ];
    }

    /**
     * The dashboard, read aloud: every block this user may see, the action
     * queue, the next few events and — for those who may see it — the latest
     * audit entries.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if (! $this->isAvailable($user)) {
            return null;
        }

        $overview = $this->overview->for($user);
        $lines = $this->blockLines($overview, array_keys(self::BLOCKS));

        $attention = $overview['attention'] ?? [];
        $lines[] = $attention !== []
            ? 'Needs your action: '.collect($attention)->map(fn (array $item): string => "{$item['label']} {$item['count']}")->implode('; ').'.'
            : 'Nothing in the action queue needs you right now.';

        if (is_array($overview['events'] ?? null) && $overview['events'] !== []) {
            $lines[] = 'Next events: '.collect($overview['events'])->take(3)->map(fn (array $event): string => sprintf(
                '%s (%s)',
                Str::limit((string) $event['title'], 60),
                $event['starts_at'] ? OrganizationClock::local(Carbon::parse($event['starts_at']))->format('D M j, g:ia') : 'date not set',
            ))->implode('; ').'.';
        }

        if (is_array($overview['activity'] ?? null) && $overview['activity'] !== []) {
            $lines[] = 'Latest changes: '.collect($overview['activity'])->take(4)->map(fn (array $entry): string => sprintf(
                '%s — %s',
                Str::limit((string) $entry['description'], 90),
                $entry['causer']['name'] ?? 'system',
            ))->implode('; ').'.';
        }

        return ContextSection::of(
            'Dashboard ('.OrganizationClock::today().')',
            $lines,
            'Only the blocks this user may see are included; say so if they ask about one that is missing.',
        );
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function overview(User $user, array $args): ToolResult
    {
        $focus = (string) ($args['focus'] ?? 'everything');
        $overview = $this->overview->for($user);

        $blocks = $focus === 'everything'
            ? ['workforce', 'attendance', 'leave', 'recruitment', 'onboarding', 'offboarding']
            : [$focus];

        $cards = [];

        foreach ($blocks as $block) {
            if (! $this->sees($user, $block) || ! is_array($overview[$block] ?? null)) {
                continue;
            }

            $lines = $this->blockLines($overview, [$block]);

            if ($lines === []) {
                continue;
            }

            $cards[] = $this->card(
                kind: 'insight',
                tone: 'info',
                badge: Str::headline($block),
                title: Str::headline($block),
                subtitle: array_shift($lines),
                meta: $lines,
            );
        }

        if ($cards === []) {
            return ToolResult::error('Read the dashboard', 'None of that is on your dashboard.');
        }

        return ToolResult::found('Read the dashboard', count($cards).' '.Str::plural('block', count($cards)), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function attention(User $user, array $args): ToolResult
    {
        $items = $this->overview->for($user)['attention'] ?? [];

        if ($items === []) {
            return ToolResult::found('Checked what needs you', 'Nothing right now', [
                $this->card(kind: 'insight', tone: 'positive', badge: 'All clear', title: 'Nothing needs your attention', subtitle: 'The action queue is empty.'),
            ]);
        }

        $cards = array_map(fn (array $item): array => $this->card(
            kind: 'insight',
            tone: in_array($item['tone'], ['rose'], true) ? 'danger' : ($item['tone'] === 'amber' ? 'warning' : 'info'),
            badge: (string) $item['count'],
            title: (string) $item['label'],
            subtitle: 'Open '.$item['href'],
            meta: [$item['count'].' waiting'],
            id: (string) $item['key'],
        ), $items);

        return ToolResult::found('Checked what needs you', count($cards).' '.Str::plural('item', count($cards)), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function activity(User $user, array $args): ToolResult
    {
        $limit = max(1, min(self::MAX_ROWS, (int) ($args['limit'] ?? 8)));
        $module = Str::lower(trim((string) ($args['module'] ?? '')));

        $cards = ActivityLog::query()
            ->with('causer:id,first_name,middle_name,last_name,suffix')
            ->when($module !== '', fn ($q) => $q->where('log_name', $module))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: Str::headline((string) $log->event),
                title: (string) $log->description,
                subtitle: $log->causer?->full_name ?? 'System',
                meta: [$log->log_name, $log->created_at?->diffForHumans()],
                id: $log->id,
            ))
            ->all();

        return ToolResult::found('Read the audit trail', count($cards).' '.Str::plural('entry', count($cards)), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function trend(User $user, array $args): ToolResult
    {
        $trend = $this->overview->for($user)['attendance']['trend'] ?? [];

        if ($trend === []) {
            return ToolResult::error('Read the attendance trend', 'There is no attendance to show.');
        }

        $values = array_column($trend, 'value');
        $working = array_values(array_filter($values, fn (int $v): bool => $v > 0));

        return ToolResult::found('Read the attendance trend', 'Last 14 days', [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: '14 days',
                title: 'Present each day',
                subtitle: $working !== []
                    ? 'Average '.round(array_sum($working) / count($working), 1).' on days anyone worked, from '.min($working).' to '.max($working)
                    : 'Nobody was recorded present',
                meta: array_map(fn (array $day): string => "{$day['label']}: {$day['value']}", $trend),
            ),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function sees(User $user, string $block): bool
    {
        foreach (self::BLOCKS[$block] ?? [] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The given blocks of an overview, in sentences. A block the overview did not
     * compute (null — the user may not see it) says nothing.
     *
     * @param  array<string, mixed>  $overview
     * @param  list<string>  $blocks
     * @return list<string>
     */
    private function blockLines(array $overview, array $blocks): array
    {
        $lines = [];

        foreach ($blocks as $block) {
            $data = $overview[$block] ?? null;

            if (! is_array($data)) {
                continue;
            }

            $line = match ($block) {
                'workforce' => sprintf(
                    'Workforce: %d active of %d on record (%d regular, %d probationary), %d on leave, %d joined this month; busiest departments %s.',
                    $data['active'] ?? 0,
                    $data['total'] ?? 0,
                    $data['regular'] ?? 0,
                    $data['probationary'] ?? 0,
                    $data['on_leave'] ?? 0,
                    $data['new_this_month'] ?? 0,
                    collect($data['top_departments'] ?? [])->take(3)->map(fn (array $d): string => "{$d['name']} {$d['count']}")->implode(', ') ?: 'none',
                ),
                'attendance' => sprintf(
                    'Attendance today: %d present, %d late, %d absent, %d on leave, of %d active staff; %s hours worked on average.',
                    $data['present'] ?? 0,
                    $data['late'] ?? 0,
                    $data['absent'] ?? 0,
                    $data['on_leave'] ?? 0,
                    $data['workforce'] ?? 0,
                    $data['avg_hours'] ?? 0,
                ),
                'leave' => sprintf(
                    'Leave: %d requests pending review, %d people on leave today, %d upcoming, %s days taken this month.',
                    $data['pending'] ?? 0,
                    $data['on_leave_today'] ?? 0,
                    $data['upcoming'] ?? 0,
                    $data['days_this_month'] ?? 0,
                ),
                'recruitment' => sprintf(
                    'Recruitment: %d open postings, %d applicants (%d in the pipeline, %d in a final stage), %d interviews coming up, %d hired this month.',
                    $data['open_postings'] ?? 0,
                    $data['total_applicants'] ?? 0,
                    $data['in_pipeline'] ?? 0,
                    $data['final_stage'] ?? 0,
                    $data['interviews_upcoming'] ?? 0,
                    $data['hired_this_month'] ?? 0,
                ),
                'onboarding' => sprintf(
                    'Onboarding: %d in progress, %d overdue tasks, %d due to finish this week, %d completed this month.',
                    $data['active'] ?? 0,
                    $data['overdue_tasks'] ?? 0,
                    $data['completing_soon'] ?? 0,
                    $data['completed_this_month'] ?? 0,
                ),
                'offboarding' => sprintf(
                    'Offboarding: %d people leaving, %d flagged clearance items, %d leaving in the next 14 days, %d completed this month.',
                    $data['active'] ?? 0,
                    $data['flagged_items'] ?? 0,
                    $data['leaving_soon'] ?? 0,
                    $data['completed_this_month'] ?? 0,
                ),
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
