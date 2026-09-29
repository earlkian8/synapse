<?php

namespace App\Services\Assistant\Modules;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Activity Logs capability (ADR 0057): the audit trail, searched the way people
 * ask about it — "who archived Maria?", "what did Jon change yesterday?", "what
 * happened in recruitment this week?".
 *
 * **Read-only, on purpose.** The screen can delete entries and clear the log;
 * the assistant cannot. It reads untrusted text every turn — documents,
 * applicant names, record fields — and an audit trail a prompt-injected model
 * could erase is not an audit trail. (The screen now records its own deletions
 * too.)
 *
 * Entries are the workspace's own (the model is tenant-scoped); who did it is
 * resolved among this workspace's accounts. The request details the screen
 * shows — IP address, browser, changed-field payloads — are not passed to the
 * model: they answer nothing a person asks here and are personal data.
 *
 * Needs `activity-logs.view`, the screen's own permission. The Dashboard
 * capability's `get_recent_activity` stays the quick "what changed lately?".
 */
class ActivityLogsModule extends Module implements ContributesTopicContext
{
    private const PERMISSION = 'activity-logs.view';

    /** How many entries a search returns at most. */
    private const MAX_ROWS = 15;

    /** How many days a summary covers when no range is given. */
    private const DEFAULT_DAYS = 7;

    public function key(): string
    {
        return 'activity-logs';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    protected function toolMap(): array
    {
        return [
            'find_activity' => 'findActivity',
            'activity_summary' => 'summary',
        ];
    }

    protected function permissionMap(): array
    {
        return array_fill_keys(array_keys($this->toolMap()), self::PERMISSION);
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot(self::PERMISSION)) {
            return $this->denied('read the activity log');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $today = OrganizationClock::today();

        return <<<TXT
        ACTIVITY LOGS — the audit trail of this workspace: who did what, to what, and when. Read-only: entries cannot be deleted here. Today is {$today}; dates are YYYY-MM-DD.
        - find_activity searches it: by words in the entry (a name, "archived"), by who did it (person), by event (created, updated, archived, deleted, restored, …), by area (e.g. recruitment, leave, user_management, roles, trash), and between dates.
        - activity_summary counts a period's activity by area, event and person (the last 7 days by default).
        - An entry says what the app recorded, nothing more — do not infer motives.
        TXT;
    }

    public function tools(User $user): array
    {
        $date = ['type' => 'STRING', 'description' => 'YYYY-MM-DD'];

        return $this->permitted($user, [
            [
                'name' => 'find_activity',
                'description' => 'Search the audit trail, newest first.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'search' => ['type' => 'STRING', 'description' => 'Words in the entry, e.g. a name.'],
                        'person' => ['type' => 'STRING', 'description' => 'Who did it, by name or email.'],
                        'event' => ['type' => 'STRING'],
                        'area' => ['type' => 'STRING'],
                        'since' => $date,
                        'until' => $date,
                    ],
                ],
            ],
            ['name' => 'activity_summary', 'description' => "Count a period's activity by area, event and person.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['since' => $date, 'until' => $date]]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['activity log', 'activity logs', 'audit trail', 'audit log', 'who changed', 'who deleted', 'who archived', 'who removed', 'who edited'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot(self::PERMISSION) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $since = OrganizationClock::at(OrganizationClock::now()->subDays(self::DEFAULT_DAYS - 1)->toDateString(), '00:00');
        $count = ActivityLog::query()->where('created_at', '>=', $since)->count();

        return ContextSection::of('Activity log', [
            "{$count} ".Str::plural('entry', $count).' in the last '.self::DEFAULT_DAYS.' days.',
            'Use find_activity to look up specific entries; nothing in the log can be deleted from here.',
        ]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findActivity(User $user, array $args): ToolResult
    {
        [$query, $filters, $error] = $this->filtered($args);

        if ($error !== null) {
            return ToolResult::error('Searched the activity log', $error);
        }

        $total = (clone $query)->count();
        $cards = $query->with('causer:id,first_name,middle_name,last_name,suffix')
            ->latest()->orderByDesc('id')->limit(self::MAX_ROWS)->get()
            ->map(fn (ActivityLog $log): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: Str::headline((string) $log->event),
                title: UntrustedText::clean((string) $log->description, 200) ?? '—',
                subtitle: $log->causer?->full_name ?? 'System',
                meta: [
                    $log->log_name !== null ? Str::headline($log->log_name) : null,
                    $log->created_at !== null ? OrganizationClock::local($log->created_at)->format('M j, Y g:i A').' ('.$log->created_at->diffForHumans().')' : null,
                ],
                id: $log->id,
            ))
            ->all();

        $label = 'Searched the activity log'.($filters !== [] ? ' — '.implode(', ', $filters) : '');

        return ToolResult::found($label, $total === 0 ? 'Nothing recorded' : ($total > count($cards) ? "{$total} entries; the latest ".count($cards).' shown' : "{$total} ".Str::plural('entry', $total)), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $args['since'] ??= OrganizationClock::now()->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        [$query, , $error] = $this->filtered(['since' => $args['since'], 'until' => $args['until'] ?? null]);

        if ($error !== null) {
            return ToolResult::error('Summarised the activity log', $error);
        }

        $total = (clone $query)->count();
        $period = ($args['until'] ?? null) !== null ? "{$args['since']} to {$args['until']}" : "since {$args['since']}";

        if ($total === 0) {
            return ToolResult::found("Summarised activity {$period}", 'Nothing recorded', []);
        }

        $areas = (clone $query)->selectRaw('log_name, count(*) as aggregate')->groupBy('log_name')->orderByDesc('aggregate')->limit(8)->pluck('aggregate', 'log_name');
        $events = (clone $query)->selectRaw('event, count(*) as aggregate')->groupBy('event')->orderByDesc('aggregate')->limit(6)->pluck('aggregate', 'event');
        $people = (clone $query)->selectRaw('causer_id, count(*) as aggregate')->groupBy('causer_id')->orderByDesc('aggregate')->limit(5)->pluck('aggregate', 'causer_id');
        $names = User::withTrashed()->whereKey($people->keys()->filter())->get()->keyBy('id');

        // Counts come back as strings on some drivers.
        $line = fn ($counts, callable $name): string => $counts->map(fn (mixed $n, mixed $key): string => $name($key).' '.(int) $n)->implode(', ');

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: 'Activity',
            title: "{$total} ".Str::plural('entry', $total)." {$period}",
            meta: [
                'By area: '.$line($areas, fn ($key): string => $key === null || $key === '' ? 'Other' : Str::headline((string) $key)),
                'By event: '.$line($events, fn ($key): string => Str::headline((string) $key)),
                'Most active: '.$line($people, fn ($key): string => $key === null || $key === '' ? 'System' : (UntrustedText::clean($names->get($key)?->full_name, 80) ?? 'Someone since removed')),
            ],
        );

        return ToolResult::found("Summarised activity {$period}", null, [$card]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The log narrowed by the filters given, a description of each, or why one
     * could not be used — a filter that is silently dropped answers a
     * different question.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: Builder<ActivityLog>, 1: list<string>, 2: string|null}
     */
    private function filtered(array $args): array
    {
        $query = ActivityLog::query();
        $filters = [];

        if (filled($args['person'] ?? null)) {
            [$person, $error] = $this->resolveAccount((string) $args['person'], archived: true);

            if ($person === null) {
                return [$query, [], $error];
            }

            $query->where('causer_id', $person->id);
            $filters[] = "by {$person->full_name}";
        }

        if (filled($args['event'] ?? null)) {
            $event = Str::snake(Str::lower(trim((string) $args['event'])));
            $query->where('event', $event);
            $filters[] = str_replace('_', ' ', $event);
        }

        if (filled($args['area'] ?? null)) {
            $area = Str::snake(Str::lower(trim((string) $args['area'])));
            $query->where('log_name', $area);
            $filters[] = 'in '.str_replace('_', ' ', $area);
        }

        foreach (['since' => ['>=', '00:00:00'], 'until' => ['<=', '23:59:59']] as $key => [$operator, $time]) {
            if (! filled($args[$key] ?? null)) {
                continue;
            }

            $date = $this->isoDate($args[$key]);

            if ($date === null) {
                return [$query, [], "“{$args[$key]}” is not a date. Use YYYY-MM-DD."];
            }

            $query->where('created_at', $operator, OrganizationClock::at($date, $time));
            $filters[] = "{$key} {$date}";
        }

        if (filled($args['search'] ?? null)) {
            $this->matchByTokens($query, (string) $args['search']);
            $filters[] = '“'.Str::limit(trim((string) $args['search']), 40).'”';
        }

        return [$query, $filters, null];
    }
}
