<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\WorkLocationRequest;
use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Setup\WorkLocationWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Locations capability (ADR 0040): the company's sites — each a fence a web or
 * app punch is placed against — who is based where, and the schedule and
 * attendance policy people based at a site default to.
 *
 * **Reading** answers "what sites do we have?", "who is based at Makati?",
 * "where is Maria based?", "how many punches were off site at the plant this
 * month?". **Doing** edits a site, bases people at it or stops basing them,
 * archives and restores it, through {@see WorkLocationWorkflow} — the Locations
 * screen's own path — against the screen's own rules
 * ({@see WorkLocationRequest::rulesFor()}).
 *
 * Deliberately left to the screen:
 *
 * - **Creating a site, and moving its pin.** A fence is a point on a map; a
 *   point the model made up, under a policy that blocks punches off site, would
 *   refuse everybody's punches. The map is where a site is placed and checked.
 * - **Permanent deletion.**
 *
 * Editing a site and archiving one wait for the user's Confirm (ADR 0049), and
 * the card says whom it reaches. Everything needs `setup.locations.view`;
 * changes `setup.locations.manage`; "where is this person based?" also needs
 * `employees.view`, checked before the name is looked up.
 */
class LocationsModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /** How many sites, or people, a read-out spells out. */
    private const MAX_LISTED = 15;

    /** How far back a site's punch count looks. */
    private const PUNCH_DAYS = 30;

    /** How many people one call may base at a site, or stop basing. */
    private const MAX_PEOPLE = 25;

    public function __construct(private readonly WorkLocationWorkflow $workflow) {}

    public function key(): string
    {
        return 'locations';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.locations.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_locations' => 'findLocations',
            'get_location' => 'getLocation',
            'update_location' => 'updateLocation',
            'base_at_location' => 'basePeople',
            'unbase_from_location' => 'unbasePeople',
            'archive_location' => 'archiveLocation',
            'restore_location' => 'restoreLocation',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_locations' => 'setup.locations.view',
            'get_location' => 'setup.locations.view',
            'update_location' => 'setup.locations.manage',
            'base_at_location' => 'setup.locations.manage',
            'unbase_from_location' => 'setup.locations.manage',
            'archive_location' => 'setup.locations.manage',
            'restore_location' => 'setup.locations.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // A fence, or the schedule and policy a site gives, reaches everybody
        // based there; archiving one stops checking punches against it.
        return ['update_location', 'archive_location'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.locations.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.locations.manage' ? 'change work locations' : 'view work locations');
        }

        // A person is looked up in the directory: checked before the name is,
        // so a made-up name and a real one get the same answer.
        if ($tool === 'find_locations' && filled($args['employee'] ?? null) && $user->cannot('employees.view')) {
            return $this->denied('look up where somebody is based');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $manage = $this->allows($user, 'setup.locations.manage')
            ? <<<'TXT'

            - update_location changes a site's name, address, fence radius (metres), default schedule, attendance policy or whether it is active; archive_location archives one. Both wait for the user's confirmation. restore_location brings one back.
            - base_at_location / unbase_from_location say who is based at a site (at most 25 people at a time); primary makes it their primary site, whose schedule and policy they default to.
            - Creating a site and moving its pin are done on the map on the Locations screen (/setup/locations), as is permanent deletion — say so if asked. Never supply coordinates.
            TXT
            : '';

        return <<<TXT
        LOCATIONS — the company's sites. Each is a fence (a point and a radius) that web and app punches are placed against, and may give the people based there a default schedule and attendance policy. Whether a punch off site is flagged or refused is each attendance policy's setting.
        - find_locations lists sites (or, given an employee, where they are based); get_location reads one: its fence, who is based there, its defaults, and its punches this month.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $location = ['type' => 'STRING', 'description' => 'The site, by name.'];
        $people = ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Names or employee numbers.'];

        return $this->permitted($user, [
            [
                'name' => 'find_locations',
                'description' => 'List work locations, or where one employee is based.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name.'],
                        'employee' => ['type' => 'STRING', 'description' => 'Name or employee number: the sites they are based at.'],
                        'archived' => ['type' => 'BOOLEAN'],
                    ],
                ],
            ],
            [
                'name' => 'get_location',
                'description' => 'Read one work location: fence, who is based there, its default schedule and policy, and its punches in the last 30 days.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['location' => $location], 'required' => ['location']],
            ],
            [
                'name' => 'update_location',
                'description' => "Change a work location's name, address, fence radius, default schedule, attendance policy or active flag. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'location' => $location,
                        'new_name' => ['type' => 'STRING'],
                        'address' => ['type' => 'STRING'],
                        'radius_meters' => ['type' => 'INTEGER'],
                        'default_schedule' => ['type' => 'STRING', 'description' => 'Work schedule name.'],
                        'clear_default_schedule' => ['type' => 'BOOLEAN'],
                        'attendance_policy' => ['type' => 'STRING', 'description' => 'Attendance policy name.'],
                        'clear_attendance_policy' => ['type' => 'BOOLEAN'],
                        'active' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['location'],
                ],
            ],
            [
                'name' => 'base_at_location',
                'description' => 'Base people at a work location, optionally as their primary site.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['location' => $location, 'people' => $people, 'primary' => ['type' => 'BOOLEAN']],
                    'required' => ['location', 'people'],
                ],
            ],
            [
                'name' => 'unbase_from_location',
                'description' => 'Stop people being based at a work location.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['location' => $location, 'people' => $people],
                    'required' => ['location', 'people'],
                ],
            ],
            [
                'name' => 'archive_location',
                'description' => 'Archive a work location; punches stop being checked against it. It can be restored.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['location' => $location], 'required' => ['location']],
            ],
            [
                'name' => 'restore_location',
                'description' => 'Restore an archived work location.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['location' => $location], 'required' => ['location']],
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
            'location', 'locations', 'work location', 'work locations', 'site', 'sites', 'geofence',
            'geofences', 'fence', 'branch', 'branches', 'office locations',
        ];
    }

    /**
     * The sites in a few lines, and whether any policy is checking a fence
     * that is not there.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.locations.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $sites = WorkLocation::query()->withCount('employees')->orderByDesc('is_active')->orderBy('name')->get();
        $checking = $this->checkingPolicies();

        return ContextSection::of('Work locations', [
            $sites->isEmpty()
                ? 'No work locations have been drawn.'
                : 'Sites: '.$sites->take(self::MAX_LISTED)->map(fn (WorkLocation $l): string => "{$l->name} ({$l->radius_meters} m fence; {$l->employees_count} based".($l->is_active ? '' : '; inactive').')')->implode('; ').'.',
            $checking === []
                ? 'No attendance policy checks where people punch.'
                : 'Policies checking where people punch: '.implode(', ', $checking).'.',
            $checking !== [] && $sites->where('is_active', true)->isEmpty()
                ? 'Warning: those policies check a fence, but there is no active site, so nothing is being checked yet.'
                : null,
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if (! in_array($tool, ['update_location', 'archive_location'], true)) {
            return null;
        }

        [$location] = $this->locate((string) ($args['location'] ?? ''));

        if ($location === null) {
            return null;
        }

        [$based, $primary] = $this->basedCounts($location);
        $who = "{$this->people($based)} ".($based === 1 ? 'is' : 'are')." based here ({$primary} as their primary site)";

        return $tool === 'archive_location'
            ? "{$who}. Their punches stop being checked against it, and its schedule and policy stop being their default; punches already made keep naming it."
            : "{$who}. The change applies to punches from now on; punches already made keep where they were judged.";
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findLocations(User $user, array $args): ToolResult
    {
        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }

            $sites = WorkLocation::query()
                ->whereHas('employees', fn (Builder $q) => $q->whereKey($employee->id))
                ->with(['employees' => fn ($q) => $q->whereKey($employee->id)])
                ->orderBy('name')
                ->get();

            $cards = $sites->map(fn (WorkLocation $l): array => $this->locationCard(
                $l,
                'find',
                'neutral',
                (bool) $l->employees->first()?->pivot?->is_primary ? 'Primary site' : 'Based here',
            ))->all();

            return ToolResult::found(
                "Looked up where {$employee->full_name} is based",
                $cards === [] ? "{$employee->full_name} is not based at any site, so their punches are checked against every active one." : null,
                $cards,
            );
        }

        $archived = ($args['archived'] ?? false) === true;
        $query = WorkLocation::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->with(['defaultSchedule:id,name', 'policy:id,name'])
            ->withCount('employees')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(30);

        $this->whereNameLike($query, (string) ($args['query'] ?? ''));

        $cards = $query->get()->map(fn (WorkLocation $l): array => $this->locationCard(
            $l,
            'find',
            'neutral',
            $archived ? 'Archived' : ($l->is_active ? 'Active' : 'Inactive'),
        ))->all();

        return ToolResult::found($archived ? 'Searched archived locations' : 'Listed work locations', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getLocation(User $user, array $args): ToolResult
    {
        [$location, $error] = $this->locate((string) ($args['location'] ?? ''));

        if ($location === null) {
            return ToolResult::error('Looked up the location', $error);
        }

        $location->load(['defaultSchedule:id,name', 'policy:id,name']);

        $people = $location->employees()
            ->orderByDesc('employee_work_locations.is_primary')
            ->orderBy('first_name')
            ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix']);

        $since = now()->subDays(self::PUNCH_DAYS);
        $punches = AttendancePunch::query()->where('work_location_id', $location->id)->where('punched_at', '>=', $since);
        $placed = (clone $punches)->count();
        $outside = (clone $punches)->where('within_geofence', false)->count();

        $card = $this->locationCard($location, 'insight', 'info', $location->is_active ? 'Active' : 'Inactive');
        $card['meta'] = array_values(array_filter([
            filled($location->address) ? 'Address: '.Str::limit((string) $location->address, 160) : 'No address on file',
            "Fence: {$location->radius_meters} m around {$location->latitude}, {$location->longitude}",
            $people->isEmpty()
                ? 'Nobody is based here'
                : $this->people($people->count()).' based here ('.$people->filter(fn (Employee $e): bool => (bool) $e->pivot->is_primary)->count().' as their primary site): '
                    .$people->take(self::MAX_LISTED)->map(fn (Employee $e): string => $e->full_name.($e->pivot->is_primary ? ' (primary)' : ''))->implode(', ')
                    .($people->count() > self::MAX_LISTED ? ' and '.($people->count() - self::MAX_LISTED).' more' : ''),
            'Default schedule: '.($location->defaultSchedule?->name ?? 'none'),
            'Attendance policy: '.($location->policy?->name ?? 'none'),
            'Last '.self::PUNCH_DAYS." days: {$placed} ".Str::plural('punch', $placed)." placed nearest this site, {$outside} of them outside the fence",
        ]));

        return ToolResult::found("Read {$location->name}", null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateLocation(User $user, array $args): ToolResult
    {
        [$location, $error] = $this->locate((string) ($args['location'] ?? ''));

        if ($location === null) {
            return ToolResult::error('Looked up the location', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);

            if (WorkLocation::query()->whereRaw('lower(name) = ?', [Str::lower($changes['name'])])->whereKeyNot($location->id)->exists()) {
                return ToolResult::error('Updated the location', (new WorkLocationRequest)->messages()['name.unique']);
            }
        }

        if (filled($args['address'] ?? null)) {
            $changes['address'] = trim((string) $args['address']);
        }

        if (isset($args['radius_meters'])) {
            $changes['radius_meters'] = (int) $args['radius_meters'];
        }

        if (is_bool($args['active'] ?? null)) {
            $changes['is_active'] = $args['active'];
        }

        foreach ([
            ['default_schedule', 'clear_default_schedule', 'default_work_schedule_id', WorkSchedule::class, 'schedule'],
            ['attendance_policy', 'clear_attendance_policy', 'attendance_policy_id', AttendancePolicy::class, 'attendance policy'],
        ] as [$argument, $clear, $column, $model, $noun]) {
            if (filled($args[$argument] ?? null)) {
                $id = $this->resolveId($model::query(), 'name', (string) $args[$argument]);

                if ($id === null) {
                    return ToolResult::error('Updated the location', "No {$noun} is called “".Str::limit((string) $args[$argument], 60).'”. They are: '.$this->catalog($model::query()->orderBy('name')->pluck('name')).'.');
                }

                $changes[$column] = $id;
            } elseif (($args[$clear] ?? false) === true) {
                $changes[$column] = null;
            }
        }

        if ($changes === []) {
            return ToolResult::error('Updated the location', 'Say what to change: its name, address, fence radius, default schedule, attendance policy or whether it is active.');
        }

        $merged = [
            ...Arr::only($location->getAttributes(), ['name', 'address', 'latitude', 'longitude', 'radius_meters', 'default_work_schedule_id', 'attendance_policy_id']),
            'is_active' => (bool) $location->is_active,
            ...$changes,
        ];

        $request = new WorkLocationRequest;

        if (($problem = $this->invalid($merged, WorkLocationRequest::rulesFor($location), $request->messages())) !== null) {
            return ToolResult::error('Updated the location', $problem);
        }

        $this->workflow->update($location, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$location->name}",
            'It applies to punches from now on; punches already made keep where they were judged.',
            $this->locationCard($location->fresh(['defaultSchedule:id,name', 'policy:id,name'])->loadCount('employees'), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function basePeople(User $user, array $args): ToolResult
    {
        [$location, $employees, $error] = $this->siteAndPeople($args);

        if ($location === null || $employees === null) {
            return ToolResult::error('Based people at the location', $error);
        }

        $primary = ($args['primary'] ?? false) === true;

        $this->workflow->base($location, $employees->pluck('id')->all(), $primary, ' via assistant');

        return ToolResult::ok(
            "Based {$this->people($employees->count())} at {$location->name}",
            $primary ? 'It is now their primary site; it is no longer anywhere else.' : null,
            $this->locationCard($location->loadCount('employees'), 'add', 'positive', $primary ? 'Primary site' : 'Based here', meta: $employees->pluck('full_name')->all()),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function unbasePeople(User $user, array $args): ToolResult
    {
        [$location, $employees, $error] = $this->siteAndPeople($args);

        if ($location === null || $employees === null) {
            return ToolResult::error('Stopped basing people at the location', $error);
        }

        $based = $location->employees()->whereIn('employees.id', $employees->pluck('id'))->pluck('employees.id')->all();
        $absent = $employees->reject(fn (Employee $e): bool => in_array($e->id, $based, true));

        if ($absent->isNotEmpty()) {
            return ToolResult::error('Stopped basing people at the location', $absent->pluck('full_name')->implode(', ').' '.($absent->count() === 1 ? 'is' : 'are')." not based at {$location->name}.");
        }

        $this->workflow->unbase($location, $based, ' via assistant');

        return ToolResult::ok(
            "Stopped basing {$this->people(count($based))} at {$location->name}",
            null,
            $this->locationCard($location->loadCount('employees'), 'cancel', 'warning', 'No longer based here', meta: $employees->pluck('full_name')->all()),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveLocation(User $user, array $args): ToolResult
    {
        [$location, $error] = $this->locate((string) ($args['location'] ?? ''));

        if ($location === null) {
            return ToolResult::error('Looked up the location', $error);
        }

        $card = $this->locationCard($location->loadCount('employees'), 'archive', 'warning', 'Archived');

        $this->workflow->archive($location, ' via assistant');

        return ToolResult::ok("Archived {$location->name}", 'Punches are no longer checked against it; it can be restored.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreLocation(User $user, array $args): ToolResult
    {
        [$location, $error] = $this->locate((string) ($args['location'] ?? ''), archived: true);

        if ($location === null) {
            return ToolResult::error('Looked up the archived location', $error);
        }

        if (WorkLocation::query()->whereRaw('lower(name) = ?', [Str::lower($location->name)])->exists()) {
            return ToolResult::error('Restored the location', (new WorkLocationRequest)->messages()['name.unique']);
        }

        $this->workflow->restore($location, ' via assistant');

        return ToolResult::ok("Restored {$location->name}", null, $this->locationCard($location->loadCount('employees'), 'start', 'positive', 'Restored'));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one site by name — an exact name first, then a partial name only
     * one site has.
     *
     * @return array{0: WorkLocation|null, 1: string}
     */
    private function locate(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which location.'];
        }

        $base = fn (): Builder => $archived ? WorkLocation::onlyTrashed() : WorkLocation::query();
        $matches = $base()->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $matches = $this->whereNameLike($base(), $needle)->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived location' : 'No location').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one location matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * The site and the people a basing call names — all of them, or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: WorkLocation|null, 1: Collection<int, Employee>|null, 2: string}
     */
    private function siteAndPeople(array $args): array
    {
        [$location, $error] = $this->locate((string) ($args['location'] ?? ''));

        if ($location === null) {
            return [null, null, $error];
        }

        [$employees, $error] = $this->resolveEmployees((array) ($args['people'] ?? []), self::MAX_PEOPLE);

        return [$location, $employees, $error];
    }

    /**
     * @param  Builder<WorkLocation>  $query
     * @return Builder<WorkLocation>
     */
    private function whereNameLike(Builder $query, string $needle): Builder
    {
        $needle = trim($needle);

        if ($needle === '') {
            return $query;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where('name', $like, '%'.addcslashes($needle, '%_\\').'%');
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * How many people are based at a site, and how many have it as primary.
     *
     * @return array{0: int, 1: int}
     */
    private function basedCounts(WorkLocation $location): array
    {
        $based = $location->employees()->count();
        $primary = $location->employees()->wherePivot('is_primary', true)->count();

        return [$based, $primary];
    }

    /**
     * The policies that check where people punch, with their mode.
     *
     * @return list<string>
     */
    private function checkingPolicies(): array
    {
        return AttendancePolicy::query()
            ->orderBy('name')
            ->get(['id', 'name', 'settings'])
            ->filter(fn (AttendancePolicy $p): bool => $p->settings()->geofence !== 'off')
            ->map(fn (AttendancePolicy $p): string => "{$p->name} (".($p->settings()->geofence === 'block' ? 'refuses' : 'flags').' punches off site)')
            ->values()
            ->all();
    }

    private function people(int $count): string
    {
        return $count.' '.Str::plural('person', $count);
    }

    /**
     * @param  list<string|null>|null  $meta
     * @return array<string, mixed>
     */
    private function locationCard(WorkLocation $location, string $kind, string $tone, string $badge, ?array $meta = null): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $location->name,
            subtitle: trim("{$location->radius_meters} m fence".(filled($location->address) ? ' · '.Str::limit((string) $location->address, 80) : '')),
            meta: $meta ?? [
                isset($location->employees_count) ? $location->employees_count.' based here' : null,
                $location->relationLoaded('defaultSchedule') && $location->defaultSchedule ? 'Schedule: '.$location->defaultSchedule->name : null,
                $location->relationLoaded('policy') && $location->policy ? 'Policy: '.$location->policy->name : null,
            ],
            id: $location->hashid,
        );
    }
}
