<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Offboarding\OffboardingProgramRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\OffboardingProgramItem;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\DayCloser;
use App\Support\Offboarding\OffboardingProgramWorkflow;
use App\Support\OffboardingProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Offboarding Programs capability (ADR 0016): the clearance templates an exit's
 * checklist is seeded from — the sign-offs, who owns each (a department, or
 * the leaver's own), and which exits a template is for (a department, an exit
 * type, or the default when nothing else matches).
 *
 * **Reading** answers "what clearance templates do we have?", "what does the IT
 * exit checklist include?", "which template would a Sales resignation get?".
 * **Doing** creates a template (from items, by copying one, or from the
 * standard list), edits it, adds, rewords or removes a sign-off, and deletes
 * one, through {@see OffboardingProgramWorkflow} — the screen's own path —
 * against the screen's own rules ({@see OffboardingProgramRequest}).
 *
 * A template only reaches exits started afterwards; one in flight keeps its
 * checklist. Changing which exits a template is for, and deleting one, wait
 * for the user's Confirm (ADR 0049), and the card says whose exit it would
 * seed ({@see OffboardingProvisioner::programFor()}, the resolver the start of
 * an exit asks). Starting, running and clearing an exit is the Offboarding
 * capability's. Everything needs `offboarding.manage-programs`, the screen's
 * own permission.
 */
class OffboardingProgramsModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const PERMISSION = 'offboarding.manage-programs';

    /** The owner a sign-off can have instead of a department. */
    private const OWN = 'own';

    public function __construct(private readonly OffboardingProgramWorkflow $workflow) {}

    public function key(): string
    {
        return 'offboarding-programs';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    protected function toolMap(): array
    {
        return [
            'find_clearance_templates' => 'findTemplates',
            'get_clearance_template' => 'getTemplate',
            'create_clearance_template' => 'createTemplate',
            'update_clearance_template' => 'updateTemplate',
            'set_clearance_template_item' => 'setItem',
            'remove_clearance_template_item' => 'removeItem',
            'delete_clearance_template' => 'deleteTemplate',
        ];
    }

    protected function permissionMap(): array
    {
        return array_fill_keys(array_keys($this->toolMap()), self::PERMISSION);
    }

    protected function confirmTools(): array
    {
        // Which exits a template seeds, and whether it exists at all, reach
        // everybody who leaves from now on.
        return ['update_clearance_template', 'delete_clearance_template'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot(self::PERMISSION)) {
            return $this->denied('manage clearance templates');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $types = implode(', ', OffboardingCase::TYPES);

        return <<<TXT
        OFFBOARDING PROGRAMS — the clearance templates an exit's checklist is seeded from when it starts: the sign-offs, each owned by a department or by the leaver's own department ("own"), and which exits a template is for (a department and/or an exit type: {$types}), or the default when none matches. With no template at all, a built-in standard list is used.
        - find_clearance_templates lists them; get_clearance_template reads one's sign-offs and whose exit it would seed.
        - create_clearance_template makes one from items, by copying one (copy_from), or from the standard list (standard). set_clearance_template_item adds or rewords a sign-off, or changes its owner; remove_clearance_template_item removes one. These change only exits started afterwards.
        - update_clearance_template (name, description, department, exit type, active, default) and delete_clearance_template wait for the user's confirmation. Starting and clearing an exit is the offboarding capability.
        TXT;
    }

    public function tools(User $user): array
    {
        $template = ['type' => 'STRING', 'description' => 'The template, by name.'];
        $owner = ['type' => 'STRING', 'description' => 'The owning department (name or code), or "own" for the leaver\'s own department.'];

        return $this->permitted($user, [
            ['name' => 'find_clearance_templates', 'description' => 'List clearance templates: what each is for, default, active, how many sign-offs.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'get_clearance_template', 'description' => "Read one clearance template's sign-offs and owners, and whose exit it would seed.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['template' => $template], 'required' => ['template']]],
            [
                'name' => 'create_clearance_template',
                'description' => 'Create a clearance template from items, by copying one, or from the standard list.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'department' => ['type' => 'STRING', 'description' => 'Only for leavers from this department.'],
                        'exit_type' => ['type' => 'STRING', 'enum' => OffboardingCase::TYPES],
                        'items' => [
                            'type' => 'ARRAY',
                            'items' => ['type' => 'OBJECT', 'properties' => ['item' => ['type' => 'STRING'], 'owner' => $owner]],
                        ],
                        'copy_from' => $template,
                        'standard' => ['type' => 'BOOLEAN', 'description' => 'Start from the built-in standard list.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_clearance_template',
                'description' => 'Change a template: name, description, which exits it is for, active, default.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'template' => $template,
                        'new_name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'department' => ['type' => 'STRING'],
                        'any_department' => ['type' => 'BOOLEAN'],
                        'exit_type' => ['type' => 'STRING', 'enum' => OffboardingCase::TYPES],
                        'any_exit_type' => ['type' => 'BOOLEAN'],
                        'active' => ['type' => 'BOOLEAN'],
                        'default' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['template'],
                ],
            ],
            [
                'name' => 'set_clearance_template_item',
                'description' => 'Add a sign-off to a template, or reword one or change its owner.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['template' => $template, 'item' => ['type' => 'STRING'], 'new_item' => ['type' => 'STRING'], 'owner' => $owner],
                    'required' => ['template', 'item'],
                ],
            ],
            ['name' => 'remove_clearance_template_item', 'description' => 'Remove a sign-off from a template.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['template' => $template, 'item' => ['type' => 'STRING']], 'required' => ['template', 'item']]],
            ['name' => 'delete_clearance_template', 'description' => 'Delete a clearance template. Exits in flight keep their checklist.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['template' => $template], 'required' => ['template']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'clearance template', 'clearance templates', 'offboarding program', 'offboarding programs',
            'exit checklist', 'exit checklists', 'clearance checklist', 'clearance checklists',
        ];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot(self::PERMISSION) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $templates = OffboardingProgram::query()->with('department:id,name')->withCount('items')->orderByDesc('is_default')->orderBy('name')->get();

        return ContextSection::of('Clearance templates', $templates->isEmpty()
            ? ['No clearance templates: every exit starts from the built-in standard list of '.count(OffboardingProvisioner::STANDARD_ITEMS).' sign-offs.']
            : [
                ...$templates->map(fn (OffboardingProgram $p): string => "{$p->name} ({$this->target($p)}; {$p->items_count} ".Str::plural('sign-off', (int) $p->items_count).($p->is_active ? '' : '; inactive').')')->all(),
                'An exit gets the most specific active template: its department and exit type, then its department, then its exit type, then the default; else the standard list.',
            ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if (! in_array($tool, ['update_clearance_template', 'delete_clearance_template'], true)) {
            return null;
        }

        [$template] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return null;
        }

        $seeds = $this->seeds($template);
        $inFlight = $template->cases()->whereNotIn('status', ['completed', 'cancelled'])->count();

        return "It would seed the exit of {$this->people($seeds)} leaving by {$this->exitWord($template)} today; {$inFlight} ".Str::plural('exit', $inFlight).' in flight came from it and keep their checklist.';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findTemplates(User $user, array $args): ToolResult
    {
        $cards = OffboardingProgram::query()->with('department:id,name')->withCount(['items', 'cases'])->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (OffboardingProgram $p): array => $this->templateCard($p, 'find', 'neutral', $p->is_default ? 'Default' : ($p->is_active ? 'Active' : 'Inactive')))
            ->all();

        return ToolResult::found('Listed clearance templates', $cards === [] ? 'None — exits use the standard list' : count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getTemplate(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        $template->load(['department:id,name', 'items.department:id,name'])->loadCount(['items', 'cases']);

        $card = $this->templateCard($template, 'insight', 'info', $template->is_default ? 'Default' : ($template->is_active ? 'Active' : 'Inactive'));
        $card['meta'] = array_values(array_filter([
            ...$template->items->map(fn (OffboardingProgramItem $i, int $n): string => ($n + 1).". {$i->item} — {$this->ownerWord($i)}")->all(),
            $template->items->isEmpty() ? 'No sign-offs yet' : null,
            "Would seed the exit of {$this->people($this->seeds($template))} leaving by {$this->exitWord($template)} today",
            "{$template->cases_count} ".Str::plural('exit', (int) $template->cases_count).' seeded from it so far',
            filled($template->description) ? 'About: '.Str::limit((string) $template->description, 200) : null,
        ]));

        return ToolResult::found("Read {$template->name}", null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createTemplate(User $user, array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        $sources = array_filter([filled($args['items'] ?? null), filled($args['copy_from'] ?? null), ($args['standard'] ?? false) === true]);

        if (count($sources) !== 1) {
            return ToolResult::error('Created the template', 'Give its sign-offs, a template to copy, or ask for the standard list — one of them.');
        }

        if (filled($args['copy_from'] ?? null)) {
            [$source, $error] = $this->locate((string) $args['copy_from']);

            if ($source === null) {
                return ToolResult::error('Created the template', $error);
            }

            $items = $source->items()->get()->map(fn (OffboardingProgramItem $i): array => [
                'item' => $i->item,
                'department_id' => $i->department_id,
                'use_employee_department' => (bool) $i->use_employee_department,
            ])->all();
        } elseif (($args['standard'] ?? false) === true) {
            $byCode = Department::query()->get(['id', 'code'])->keyBy(fn (Department $d): string => strtoupper((string) $d->code));

            $items = array_map(fn (array $i): array => [
                'item' => $i['item'],
                'department_id' => $i['department'] === '__own__' ? null : $byCode->get(strtoupper($i['department']))?->id,
                'use_employee_department' => $i['department'] === '__own__',
            ], OffboardingProvisioner::STANDARD_ITEMS);
        } else {
            $items = [];

            foreach ((array) $args['items'] as $row) {
                [$item, $error] = $this->itemRow((array) $row);

                if ($item === null) {
                    return ToolResult::error('Created the template', $error);
                }

                $items[] = $item;
            }
        }

        [$department, $error] = $this->department($args['department'] ?? null);

        if ($error !== null) {
            return ToolResult::error('Created the template', $error);
        }

        $attributes = [
            'name' => $name,
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'department_id' => $department,
            'exit_type' => filled($args['exit_type'] ?? null) ? (string) $args['exit_type'] : null,
            'is_default' => false,
            'is_active' => true,
        ];

        if (($problem = $this->invalid([...$attributes, 'items' => $items], (new OffboardingProgramRequest)->rules(), [], ['items.*.item' => 'sign-off']) ?? $this->nameTaken($name)) !== null) {
            return ToolResult::error('Created the template', $problem);
        }

        $template = $this->workflow->create($attributes, $items, ' via assistant');

        return ToolResult::ok(
            "Created {$template->name}",
            'It seeds exits started from now on '.($template->department_id === null && $template->exit_type === null ? 'only once it is made the default or given a department or exit type.' : "for {$this->target($template)}."),
            $this->templateCard($template->load('department:id,name')->loadCount(['items', 'cases']), 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateTemplate(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['description'] ?? null)) {
            $changes['description'] = trim((string) $args['description']);
        }

        if (filled($args['department'] ?? null)) {
            [$department, $error] = $this->department($args['department']);

            if ($error !== null) {
                return ToolResult::error('Updated the template', $error);
            }

            $changes['department_id'] = $department;
        } elseif (($args['any_department'] ?? false) === true) {
            $changes['department_id'] = null;
        }

        if (filled($args['exit_type'] ?? null)) {
            $changes['exit_type'] = (string) $args['exit_type'];
        } elseif (($args['any_exit_type'] ?? false) === true) {
            $changes['exit_type'] = null;
        }

        foreach (['active' => 'is_active', 'default' => 'is_default'] as $argument => $column) {
            if (is_bool($args[$argument] ?? null)) {
                $changes[$column] = $args[$argument];
            }
        }

        if ($changes === []) {
            return ToolResult::error('Updated the template', 'Say what to change: its name, description, department, exit type, whether it is active, or whether it is the default.');
        }

        $merged = [
            'name' => $template->name, 'description' => $template->description, 'department_id' => $template->department_id,
            'exit_type' => $template->exit_type, 'is_default' => (bool) $template->is_default, 'is_active' => (bool) $template->is_active,
            ...$changes,
        ];

        $problem = $this->invalid($merged, collect((new OffboardingProgramRequest)->rules())->reject(fn ($rule, string $key): bool => str_starts_with($key, 'items'))->all())
            ?? (isset($changes['name']) ? $this->nameTaken($changes['name'], $template) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the template', $problem);
        }

        $this->workflow->update($template, $changes, null, ' via assistant');

        return ToolResult::ok(
            "Updated {$template->name}",
            'Exits started from now on use it this way; exits in flight keep their checklist.',
            $this->templateCard($template->load('department:id,name')->loadCount(['items', 'cases']), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setItem(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        $items = $this->currentItems($template);
        $wanted = Str::lower(trim((string) ($args['item'] ?? '')));
        $index = collect($items)->search(fn (array $i): bool => Str::lower($i['item']) === $wanted);

        if ($index === false) {
            [$item, $error] = $this->itemRow(['item' => $args['item'] ?? '', 'owner' => $args['owner'] ?? null]);
            $verb = 'Added';
        } else {
            [$item, $error] = $this->itemRow([
                'item' => filled($args['new_item'] ?? null) ? $args['new_item'] : $items[$index]['item'],
                'owner' => $args['owner'] ?? null,
            ], $items[$index]);
            $verb = 'Updated';
        }

        if ($item === null) {
            return ToolResult::error('Set the sign-off', $error);
        }

        if ($index === false) {
            $items[] = $item;
        } else {
            $items[$index] = $item;
        }

        return $this->saveItems($template, $items, "{$verb} “{$item['item']}” in {$template->name}", 'Set the sign-off');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeItem(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        $items = collect($this->currentItems($template));
        $wanted = Str::lower(trim((string) ($args['item'] ?? '')));
        $matches = $items->filter(fn (array $i): bool => Str::lower($i['item']) === $wanted);

        if ($matches->isEmpty() && $wanted !== '') {
            $matches = $items->filter(fn (array $i): bool => str_contains(Str::lower($i['item']), $wanted));
        }

        if ($matches->count() !== 1) {
            return ToolResult::error('Removed the sign-off', $matches->isEmpty()
                ? "{$template->name} has no sign-off matching “".Str::limit((string) ($args['item'] ?? ''), 60).'”.'
                : 'More than one sign-off matches: '.$matches->pluck('item')->implode('; ').'.');
        }

        $removed = $matches->first()['item'];

        return $this->saveItems($template, $items->forget($matches->keys()->first())->values()->all(), "Removed “{$removed}” from {$template->name}", 'Removed the sign-off');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteTemplate(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locate((string) ($args['template'] ?? ''));

        if ($template === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        $card = $this->templateCard($template->load('department:id,name')->loadCount(['items', 'cases']), 'archive', 'warning', 'Deleted');

        $this->workflow->delete($template, ' via assistant');

        return ToolResult::ok("Deleted {$card['title']}", 'Exits in flight keep their checklist.', $card);
    }

    // ── Items ────────────────────────────────────────────────────────────────

    /**
     * @return list<array{item: string, department_id: int|null, use_employee_department: bool}>
     */
    private function currentItems(OffboardingProgram $template): array
    {
        return $template->items()->get()->map(fn (OffboardingProgramItem $i): array => [
            'item' => $i->item,
            'department_id' => $i->department_id,
            'use_employee_department' => (bool) $i->use_employee_department,
        ])->values()->all();
    }

    /**
     * A sign-off as the editor posts it — worded, and owned by a department, the
     * leaver's own, or nobody — or why not. What the arguments leave out comes
     * from `$was`.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $was
     * @return array{0: array{item: string, department_id: int|null, use_employee_department: bool}|null, 1: string}
     */
    private function itemRow(array $row, ?array $was = null): array
    {
        $text = trim(is_scalar($row['item'] ?? null) ? (string) $row['item'] : '');

        if ($text === '') {
            return [null, 'Say what the sign-off is.'];
        }

        $owner = trim(is_scalar($row['owner'] ?? null) ? (string) $row['owner'] : '');

        if ($owner === '') {
            return [['item' => $text, 'department_id' => $was['department_id'] ?? null, 'use_employee_department' => (bool) ($was['use_employee_department'] ?? false)], ''];
        }

        if (in_array(Str::lower($owner), [self::OWN, 'own department', "employee's department", 'their department'], true)) {
            return [['item' => $text, 'department_id' => null, 'use_employee_department' => true], ''];
        }

        [$department, $error] = $this->department($owner);

        return $error !== null
            ? [null, $error]
            : [['item' => $text, 'department_id' => $department, 'use_employee_department' => false], ''];
    }

    /**
     * Validate a template's sign-offs by the screen's rules and save them.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function saveItems(OffboardingProgram $template, array $items, string $label, string $failure): ToolResult
    {
        if (($problem = $this->invalid(['items' => $items], ['items' => ['array'], ...collect((new OffboardingProgramRequest)->rules())->filter(fn ($rule, string $key): bool => str_starts_with($key, 'items.'))->all()], [], ['items.*.item' => 'sign-off'])) !== null) {
            return ToolResult::error($failure, $problem);
        }

        $this->workflow->update($template, [], $items, ' via assistant');

        return ToolResult::ok($label, 'Exits started from now on get it; exits in flight keep their checklist.', $this->templateCard($template->load('department:id,name')->loadCount(['items', 'cases']), 'edit', 'info', 'Sign-offs updated'));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one template by name — exact first, then a partial name only one
     * has. Inactive ones count: this is the catalogue, not the exit picker.
     *
     * @return array{0: OffboardingProgram|null, 1: string}
     */
    private function locate(string $typed): array
    {
        $typed = trim($typed);
        $needle = Str::lower($typed);

        if ($needle === '') {
            return [null, 'Say which template.'];
        }

        $templates = OffboardingProgram::query()->orderBy('name')->get();
        $matches = $templates->filter(fn (OffboardingProgram $p): bool => Str::lower($p->name) === $needle);

        if ($matches->isEmpty()) {
            $matches = $templates->filter(fn (OffboardingProgram $p): bool => str_contains(Str::lower($p->name), $needle));
        }

        return match (true) {
            $matches->count() === 1 => [$matches->first(), ''],
            $matches->isEmpty() => [null, 'No clearance template matches “'.Str::limit($typed, 60).'”.'.($templates->isNotEmpty() ? ' The templates: '.$this->catalog($templates->pluck('name')).'.' : '')],
            default => [null, 'More than one template matches: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * A department by name or code, as an id — or null for none, or why not.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function department(mixed $needle): array
    {
        $needle = trim(is_scalar($needle) ? (string) $needle : '');

        if ($needle === '') {
            return [null, null];
        }

        $id = Department::query()
            ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [Str::lower($needle)])->orWhereRaw('lower(code) = ?', [Str::lower($needle)]))
            ->value('id');

        return $id !== null
            ? [(int) $id, null]
            : [null, 'No department is called “'.Str::limit($needle, 60).'”. The departments: '.$this->catalog(Department::query()->orderBy('name')->pluck('name')).'.'];
    }

    /**
     * Refuse a name another template has in any case: the exit tools pick one
     * by name.
     */
    private function nameTaken(string $name, ?OffboardingProgram $except = null): ?string
    {
        $taken = OffboardingProgram::query()
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? "There is already a clearance template called “{$name}”." : null;
    }

    /**
     * How many of the people whose days are counted this template would seed
     * the exit of today, asked of the resolver the start of an exit asks —
     * leaving by its own exit type, or by resignation when it names none.
     */
    private function seeds(OffboardingProgram $template): int
    {
        if (! $template->is_active) {
            return 0;
        }

        $type = $template->exit_type ?? 'resignation';

        return Employee::query()
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->get(['id', 'department_id'])
            ->filter(fn (Employee $e): bool => OffboardingProvisioner::programFor($e, $type)?->id === $template->id)
            ->count();
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    private function target(OffboardingProgram $template): string
    {
        $parts = array_filter([
            $template->department_id !== null ? ($template->department?->name ?? 'a department').' leavers' : null,
            $template->exit_type !== null ? str_replace('_', ' ', $template->exit_type) : null,
        ]);

        return $parts === [] ? ($template->is_default ? 'the default' : 'no department or exit type') : implode(', ', $parts);
    }

    private function exitWord(OffboardingProgram $template): string
    {
        return str_replace('_', ' ', $template->exit_type ?? 'resignation');
    }

    private function ownerWord(OffboardingProgramItem $item): string
    {
        return match (true) {
            (bool) $item->use_employee_department => "the leaver's own department",
            $item->department !== null => $item->department->name,
            default => 'no department',
        };
    }

    private function people(int $count): string
    {
        return $count.' '.Str::plural('person', $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateCard(OffboardingProgram $template, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $template->name,
            subtitle: 'For '.$this->target($template),
            meta: [
                isset($template->items_count) ? $template->items_count.' '.Str::plural('sign-off', (int) $template->items_count) : null,
                isset($template->cases_count) ? $template->cases_count.' '.Str::plural('exit', (int) $template->cases_count).' seeded' : null,
            ],
            id: $template->hashid,
        );
    }
}
