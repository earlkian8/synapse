<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Awards\EmployeeAwardRequest;
use App\Models\AwardType;
use App\Models\EmployeeAward;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\Awards\AwardException;
use App\Support\Awards\AwardNominator;
use App\Support\Awards\AwardWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Awards capability: the recognition feed, the nomination board, and giving
 * recognition.
 *
 * **Reading** answers "who was recognised this month?", "what has Maria been
 * awarded?", and — for those who may see the nomination board — "who should
 * get Employee of the Month?", from {@see AwardNominator}, the class the board
 * itself is drawn from, so the ranking in chat is the ranking on the screen,
 * breakdown and fairness flag included. **Doing** gives, revises and takes back
 * recognitions through {@see AwardWorkflow}, the path the screens take, so a
 * retired award type or a future date is refused in the screens' own words.
 *
 * Disclosure follows the screens: the feed needs `awards.view`, and the
 * nomination board — it ranks people against each other — `awards.manage`, as
 * does every change. A person's own recognitions are theirs to ask about (the
 * mobile app shows them). Taking a recognition back waits for the user's Confirm
 * (ADR 0049).
 */
class AwardsModule extends Module implements ContributesContext, ContributesTopicContext
{
    /** How many results a list returns. */
    private const MAX_RESULTS = 12;

    /** How many of a person's recognitions their brief lists. */
    private const CONTEXT_AWARDS = 5;

    /** How many award types a front-runner read covers. */
    private const MAX_TYPES = 6;

    public function __construct(
        private readonly AwardWorkflow $workflow,
        private readonly AwardNominator $nominator,
    ) {}

    public function key(): string
    {
        return 'awards';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('awards.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_awards' => 'findAwards',
            'list_award_types' => 'listTypes',
            'awards_summary' => 'summary',
            'get_award_nominees' => 'nominees',
            'give_award' => 'give',
            'update_award' => 'revise',
            'remove_award' => 'remove',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_awards' => 'awards.view',
            'list_award_types' => 'awards.view',
            'awards_summary' => 'awards.view',
            // The board ranks people against each other — manage-gated on the
            // screen, and so here.
            'get_award_nominees' => 'awards.manage',
            'give_award' => 'awards.manage',
            'update_award' => 'awards.manage',
            'remove_award' => 'awards.manage',
        ];
    }

    protected function confirmTools(): array
    {
        return ['remove_award'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'awards.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'awards.manage'
                ? ($tool === 'get_award_nominees' ? 'see the nomination board' : 'give or change recognitions')
                : 'view recognitions');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $types = $this->catalog(AwardType::query()->active()->orderBy('name')->pluck('name'));

        $manage = $this->allows($user, 'awards.manage')
            ? <<<'TXT'

            - get_award_nominees ranks who deserves an award most right now, on appraisals, the performance forecast, attendance, training, tenure and time since last recognised — for one award type (its shortlist and each person's breakdown) or the front-runner for every type. A recent winner of the same award is flagged.
            - give_award recognises one person (the date defaults to today, and cannot be in the future); update_award changes an award's type, date or reason; remove_award takes one back, and waits for the user's confirmation. Only active award types can be given.
            - When asked to write the citation, write one or two warm, specific sentences grounded in what you were given — never invented achievements.
            TXT
            : '';

        return <<<TXT
        AWARDS — recognitions given to employees, each of a configured award type.
        - find_awards lists recognitions (by person, award type, since a date); list_award_types lists the catalogue; awards_summary reads the recognition picture.
        - Pass people by name or employee number, award types by name, and dates as YYYY-MM-DD.{$manage}
          Award types given out: {$types}
        TXT;
    }

    public function tools(User $user): array
    {
        $employee = ['type' => 'STRING', 'description' => 'Employee name or employee number.'];
        $type = ['type' => 'STRING', 'description' => 'Award type name.'];
        $date = ['type' => 'STRING', 'description' => 'The date it was awarded, YYYY-MM-DD.'];

        return $this->permitted($user, [
            [
                'name' => 'find_awards',
                'description' => 'List recognitions, newest first, optionally by employee, award type, and since a date.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'award_type' => $type,
                        'since' => ['type' => 'STRING', 'description' => 'Only awards on or after this date, YYYY-MM-DD.'],
                    ],
                ],
            ],
            [
                'name' => 'list_award_types',
                'description' => 'The award catalogue: every award type, whether it is still given out, and how often it has been given.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'awards_summary',
                'description' => 'The recognition picture: totals, this month, people recognised, the latest awards and the most-given types.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_award_nominees',
                'description' => 'Who deserves an award most right now: the ranked shortlist for one award type with each nominee\'s score breakdown, or the front-runner for every active type.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['award_type' => $type],
                ],
            ],
            [
                'name' => 'give_award',
                'description' => 'Recognise one employee with an award.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'award_type' => $type,
                        'reason' => ['type' => 'STRING', 'description' => 'The citation: why they are recognised.'],
                        'awarded_on' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; defaults to today.'],
                    ],
                    'required' => ['employee', 'award_type'],
                ],
            ],
            [
                'name' => 'update_award',
                'description' => "Change one of an employee's awards: its type, date or reason. Identify it by the employee and, when they have several, its current type or date.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'award_type' => ['type' => 'STRING', 'description' => 'The award\'s current type, to identify it.'],
                        'awarded_on' => ['type' => 'STRING', 'description' => 'The award\'s current date, YYYY-MM-DD, to identify it.'],
                        'new_award_type' => ['type' => 'STRING', 'description' => 'Change it to this award type.'],
                        'new_awarded_on' => ['type' => 'STRING', 'description' => 'Change its date, YYYY-MM-DD.'],
                        'reason' => ['type' => 'STRING', 'description' => 'The new citation.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'remove_award',
                'description' => "Take back one of an employee's awards. Identify it by the employee and, when they have several, its type or date.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'award_type' => $type, 'awarded_on' => $date],
                    'required' => ['employee'],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * A person's recognitions: how many, and the latest few with their citation.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        // One's own recognitions are one's own to read — the mobile app shows
        // them — so the self case needs no permission.
        if ($employee === null || ($user->cannot('awards.view') && ! $subject->isSelf)) {
            return null;
        }

        $awards = EmployeeAward::query()
            ->where('employee_id', $employee->id)
            ->with(['awardType:id,name', 'grantedBy:id,first_name,last_name'])
            ->latestFirst()
            ->get();

        if ($awards->isEmpty()) {
            return ContextSection::of('Awards & recognition', ['No recognitions on record.']);
        }

        return ContextSection::of('Awards & recognition', [
            $awards->count().' '.Str::plural('recognition', $awards->count()).' on record, the latest on '.$awards->first()->awarded_on?->format('M j, Y').'.',
            ...$awards->take(self::CONTEXT_AWARDS)->map(fn (EmployeeAward $a): string => $this->describeAward($a))->all(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'award', 'awards', 'awarded', 'recognition', 'recognitions', 'recognise', 'recognize', 'recognised',
            'recognized', 'kudos', 'nominee', 'nominees', 'nomination', 'nominations', 'nominate', 'shortlist',
            'front-runner', 'frontrunner', 'employee of the month', 'parangal', 'gantimpala',
        ];
    }

    /**
     * The recognition picture — and, for those who may see the board, who is
     * leading for each award right now.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('awards.view')) {
            return null;
        }

        $lines = $this->pictureLines();

        if ($user->can('awards.manage')) {
            $leaders = collect($this->nominator->board())
                ->take(self::MAX_TYPES)
                ->map(fn (array $entry): ?string => $this->frontRunnerLine($entry))
                ->filter()
                ->values();

            if ($leaders->isNotEmpty()) {
                $lines[] = 'Front-runners on the nomination board: '.$leaders->implode('; ').'.';
            }
        }

        return ContextSection::of('Awards & recognition', $lines);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findAwards(User $user, array $args): ToolResult
    {
        $employee = null;
        $type = null;
        $since = null;

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        if (filled($args['award_type'] ?? null)) {
            [$type, $error] = $this->locateType((string) $args['award_type']);

            if ($type === null) {
                return ToolResult::error('Looked up the award type', $error);
            }
        }

        if (filled($args['since'] ?? null) && ($since = $this->isoDate($args['since'])) === null) {
            return ToolResult::error('Searched recognitions', 'Give the date as YYYY-MM-DD.');
        }

        $awards = EmployeeAward::query()
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no,photo', 'awardType:id,name', 'grantedBy:id,first_name,last_name'])
            ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->when($type !== null, fn (Builder $q) => $q->where('award_type_id', $type->id))
            ->when($since !== null, fn (Builder $q) => $q->whereDate('awarded_on', '>=', $since))
            ->latestFirst()
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $awards->map(fn (EmployeeAward $a): array => $this->awardCard($a, 'find', 'neutral', $a->awardType?->name ?? 'Award'))->all();

        return ToolResult::found('Searched recognitions', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function listTypes(User $user, array $args): ToolResult
    {
        $cards = AwardType::query()
            ->withCount('awards')
            ->catalogueOrder()
            ->limit(30)
            ->get()
            ->map(fn (AwardType $t): array => $this->card(
                kind: 'find',
                tone: $t->is_active ? 'positive' : 'neutral',
                badge: $t->is_active ? 'Active' : 'Retired',
                title: $t->name,
                subtitle: filled($t->description) ? Str::limit((string) $t->description, 140) : null,
                meta: [$t->awards_count.' given'],
                id: $t->hashid,
            ))
            ->all();

        return ToolResult::found('Listed award types', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $lines = $this->pictureLines();

        return ToolResult::found('Read the recognition picture', null, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Awards',
                title: 'Recognition across the company',
                subtitle: array_shift($lines),
                meta: $lines,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function nominees(User $user, array $args): ToolResult
    {
        if (! filled($args['award_type'] ?? null)) {
            $board = collect($this->nominator->board())->take(self::MAX_TYPES);

            if ($board->isEmpty()) {
                return ToolResult::error('Read the nomination board', 'No award types are active yet.');
            }

            $cards = $board->map(function (array $entry): array {
                $leader = $entry['nominees'][0] ?? null;

                return $this->card(
                    kind: 'insight',
                    tone: $leader === null ? 'neutral' : 'info',
                    badge: $entry['profile']['label'],
                    title: $entry['type']['name'],
                    subtitle: $leader === null
                        ? 'Nobody has enough signals yet'
                        : 'Front-runner: '.$leader['employee']['full_name'].' ('.$leader['score'].', '.$leader['band'].')',
                    meta: array_map(
                        fn (array $n): string => "#{$n['rank']} {$n['employee']['full_name']} {$n['score']}".($n['recent_winner'] ? ' (won it recently)' : ''),
                        array_slice($entry['nominees'], 0, 3),
                    ),
                    id: $entry['type']['id'],
                );
            })->values()->all();

            return ToolResult::found('Read the nomination board', count($cards).' award '.Str::plural('type', count($cards)), $cards);
        }

        [$type, $error] = $this->locateType((string) $args['award_type']);

        if ($type === null) {
            return ToolResult::error('Looked up the award type', $error);
        }

        $entry = $this->nominator->for($type);

        if ($entry['nominees'] === []) {
            return ToolResult::error("Ranked nominees for {$type->name}", 'Nobody has enough signals to rank yet.');
        }

        $cards = array_map(fn (array $n): array => $this->card(
            kind: 'insight',
            tone: match ($n['band']) {
                'strong' => 'positive',
                'promising' => 'info',
                default => 'neutral',
            },
            badge: '#'.$n['rank'],
            title: $n['employee']['full_name'],
            subtitle: 'Fit '.$n['score'].' of 100 ('.$n['band'].')'.($n['recent_winner'] ? ' — won this award '.$n['won_months_ago'].' months ago' : ''),
            meta: array_map(fn (array $c): string => "{$c['label']}: {$c['detail']} ({$c['points']}/{$c['max']})", $n['components']),
            avatar: [
                'name' => $n['employee']['full_name'],
                'initials' => $n['employee']['initials'],
                'photo' => $n['employee']['photo'],
            ],
            id: $n['employee']['id'],
        ), $entry['nominees']);

        return ToolResult::found("Ranked nominees for {$type->name}", $entry['profile']['label'].' — '.$entry['profile']['hint'], $cards);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function give(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say who to recognise.');

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        [$type, $error] = $this->locateType((string) ($args['award_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the award type', $error);
        }

        $date = OrganizationClock::today();

        if (filled($args['awarded_on'] ?? null) && ($date = $this->isoDate($args['awarded_on'])) === null) {
            return ToolResult::error('Gave the award', 'Give the date as YYYY-MM-DD.');
        }

        $reason = filled($args['reason'] ?? null) ? trim((string) $args['reason']) : null;

        if (($problem = $this->invalid(['awarded_on' => $date, 'reason' => $reason], $this->awardRules())) !== null) {
            return ToolResult::error('Gave the award', $problem);
        }

        try {
            $award = $this->workflow->give($employee, $type, $date, $reason, $user, ' via assistant');
        } catch (AwardException $e) {
            return ToolResult::error('Gave the award', $e->getMessage());
        }

        $award->load(['employee', 'awardType:id,name', 'grantedBy:id,first_name,last_name']);

        return ToolResult::ok(
            "Recognised {$employee->full_name}",
            $type->name,
            $this->awardCard($award, 'award', 'positive', 'Recognised'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function revise(User $user, array $args): ToolResult
    {
        [$award, $error] = $this->locateAward($args);

        if ($award === null) {
            return ToolResult::error('Looked up the award', $error);
        }

        $changes = [];

        if (filled($args['new_award_type'] ?? null)) {
            [$type, $error] = $this->locateType((string) $args['new_award_type']);

            if ($type === null) {
                return ToolResult::error('Looked up the award type', $error);
            }

            $changes['award_type_id'] = $type->id;
        }

        if (filled($args['new_awarded_on'] ?? null)) {
            if (($date = $this->isoDate($args['new_awarded_on'])) === null) {
                return ToolResult::error('Updated the award', 'Give the date as YYYY-MM-DD.');
            }

            $changes['awarded_on'] = $date;
        }

        if (filled($args['reason'] ?? null)) {
            $changes['reason'] = trim((string) $args['reason']);
        }

        if ($changes === []) {
            return ToolResult::error('Updated the award', 'Say what to change: its type, date or reason.');
        }

        $merged = ['awarded_on' => $award->awarded_on?->toDateString(), 'reason' => $award->reason, ...Arr::only($changes, ['awarded_on', 'reason'])];

        if (($problem = $this->invalid($merged, $this->awardRules())) !== null) {
            return ToolResult::error('Updated the award', $problem);
        }

        try {
            $this->workflow->revise($award, $changes, ' via assistant');
        } catch (AwardException $e) {
            return ToolResult::error('Updated the award', $e->getMessage());
        }

        $award->refresh()->load(['employee', 'awardType:id,name', 'grantedBy:id,first_name,last_name']);

        return ToolResult::ok(
            "Updated {$award->employee?->full_name}'s award",
            $award->awardType?->name,
            $this->awardCard($award, 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function remove(User $user, array $args): ToolResult
    {
        [$award, $error] = $this->locateAward($args);

        if ($award === null) {
            return ToolResult::error('Looked up the award', $error);
        }

        $card = $this->awardCard($award, 'cancel', 'warning', 'Removed');
        $name = $award->employee?->full_name;

        $this->workflow->remove($award, ' via assistant');

        return ToolResult::ok("Removed {$name}'s {$award->awardType?->name}", null, $card);
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one award type (archived ones excluded) for a name, or why not.
     *
     * @return array{0: AwardType|null, 1: string}
     */
    private function locateType(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [null, 'Say which award.'];
        }

        $exact = AwardType::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->first();

        if ($exact !== null) {
            return [$exact, ''];
        }

        $like = AwardType::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $matches = AwardType::query()->where('name', $like, '%'.addcslashes($name, '%_\\').'%')->catalogueOrder()->limit(6)->get();

        return match (true) {
            $matches->isEmpty() => [null, 'No award type matches “'.Str::limit($name, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one award type matches “'.Str::limit($name, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * The one award the arguments mean: this person's, narrowed by type and
     * date — or why it is not one.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: EmployeeAward|null, 1: string}
     */
    private function locateAward(array $args): array
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say whose award.');

        if ($employee === null) {
            return [null, $error];
        }

        $type = null;

        if (filled($args['award_type'] ?? null)) {
            [$type, $error] = $this->locateType((string) $args['award_type']);

            if ($type === null) {
                return [null, $error];
            }
        }

        $date = null;

        if (filled($args['awarded_on'] ?? null) && ($date = $this->isoDate($args['awarded_on'])) === null) {
            return [null, 'Give the date as YYYY-MM-DD.'];
        }

        $awards = EmployeeAward::query()
            ->with(['employee', 'awardType:id,name', 'grantedBy:id,first_name,last_name'])
            ->where('employee_id', $employee->id)
            ->when($type !== null, fn (Builder $q) => $q->where('award_type_id', $type->id))
            ->when($date !== null, fn (Builder $q) => $q->whereDate('awarded_on', $date))
            ->latestFirst()
            ->limit(6)
            ->get();

        return match (true) {
            $awards->isEmpty() => [null, "{$employee->full_name} has no award like that."],
            $awards->count() === 1 => [$awards->first(), ''],
            default => [null, "{$employee->full_name} has more than one: ".$awards->map(fn (EmployeeAward $a): string => ($a->awardType?->name ?? 'Award').' on '.$a->awarded_on?->toDateString())->implode('; ').'. Say which type or date.'],
        };
    }

    /**
     * The give/edit form's own rules for the date and the citation.
     *
     * @return array<string, mixed>
     */
    private function awardRules(): array
    {
        return Arr::only((new EmployeeAwardRequest)->rules(), ['awarded_on', 'reason']);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * The recognition picture in lines — the feed's tiles, read aloud.
     *
     * @return list<string>
     */
    private function pictureLines(): array
    {
        $startOfMonth = Carbon::parse(OrganizationClock::today())->startOfMonth()->toDateString();
        $yearAgo = Carbon::parse(OrganizationClock::today())->subYear()->toDateString();

        $total = EmployeeAward::query()->count();

        if ($total === 0) {
            $types = AwardType::query()->active()->count();

            return ["No recognitions have been given yet; {$types} ".Str::plural('award type', $types).' ready to give.'];
        }

        $latest = EmployeeAward::query()
            ->with(['employee:id,first_name,middle_name,last_name,suffix', 'awardType:id,name'])
            ->latestFirst()
            ->limit(4)
            ->get();

        $mostGiven = EmployeeAward::query()
            ->whereDate('awarded_on', '>=', $yearAgo)
            ->selectRaw('award_type_id, count(*) as total')
            ->groupBy('award_type_id')
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row): string => (AwardType::withTrashed()->find($row->award_type_id)?->name ?? 'Award').' '.$row->total);

        return array_values(array_filter([
            sprintf(
                'Recognitions: %d all-time, %d this month, %d %s recognised; %d %s given out.',
                $total,
                EmployeeAward::query()->whereDate('awarded_on', '>=', $startOfMonth)->count(),
                $people = EmployeeAward::query()->distinct()->count('employee_id'),
                Str::plural('person', $people),
                $types = AwardType::query()->active()->count(),
                Str::plural('award type', $types),
            ),
            'Latest: '.$latest->map(fn (EmployeeAward $a): string => sprintf(
                '%s — %s (%s)',
                $a->employee?->full_name ?? 'Unknown',
                $a->awardType?->name ?? 'Award',
                $a->awarded_on?->format('M j, Y') ?? 'undated',
            ))->implode('; ').'.',
            $mostGiven->isNotEmpty() ? 'Most given in the last 12 months: '.$mostGiven->implode('; ').'.' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function frontRunnerLine(array $entry): ?string
    {
        $leader = $entry['nominees'][0] ?? null;

        if ($leader === null) {
            return null;
        }

        return sprintf(
            '%s — %s (fit %d, %s)%s',
            $entry['type']['name'],
            $leader['employee']['full_name'],
            $leader['score'],
            $leader['band'],
            $leader['recent_winner'] ? ', a recent winner of it' : '',
        );
    }

    private function describeAward(EmployeeAward $a): string
    {
        $by = $a->grantedBy ? trim($a->grantedBy->first_name.' '.$a->grantedBy->last_name) : null;

        return sprintf(
            '%s on %s%s%s.',
            $a->awardType?->name ?? 'Award',
            $a->awarded_on?->format('M j, Y') ?? 'an unknown date',
            filled($a->reason) ? ' — '.Str::limit((string) $a->reason, 160) : '',
            $by ? " (given by {$by})" : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function awardCard(EmployeeAward $a, string $kind, string $tone, string $badge): array
    {
        $employee = $a->employee;
        $by = $a->grantedBy ? trim($a->grantedBy->first_name.' '.$a->grantedBy->last_name) : null;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: trim(($a->awardType?->name ?? 'Award').' · '.($a->awarded_on?->format('M j, Y') ?? 'undated')),
            meta: [
                filled($a->reason) ? Str::limit((string) $a->reason, 160) : null,
                $by ? "Given by {$by}" : null,
            ],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $a->id,
        );
    }
}
