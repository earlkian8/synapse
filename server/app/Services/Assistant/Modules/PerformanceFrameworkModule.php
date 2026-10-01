<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Setup\EvaluationPeriodRequest;
use App\Http\Requests\Setup\KpiCriterionRequest;
use App\Http\Requests\Setup\RatingScaleRequest;
use App\Http\Requests\Setup\ReviewTemplateRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EvaluationPeriod;
use App\Models\KpiCriterion;
use App\Models\Position;
use App\Models\RatingScale;
use App\Models\ReviewTemplate;
use App\Models\ReviewTemplateItem;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\DayCloser;
use App\Support\Performance\RatingModel;
use App\Support\Performance\RatingScales;
use App\Support\Performance\TemplateResolver;
use App\Support\Setup\PerformanceFrameworkWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Performance Framework capability (ADR 0028): the appraisal frameworks the
 * Performance module reviews people against — their weighted sections and the
 * items inside them, who each applies to — and the criteria catalogue, rating
 * scales and review cycles they draw on.
 *
 * **Reading** answers "what does the Staff framework measure?", "which framework
 * is Sales reviewed with?", "what criteria do we have?", "what scales do we
 * use?". **Doing** edits a framework a line at a time — an item's weight, a
 * section's weight, who it applies to — makes one from a copy or from catalogue
 * criteria, and keeps the catalogue, the scales and the cycles, all through
 * {@see PerformanceFrameworkWorkflow} and the screen's own rules: a framework is
 * rebuilt whole and held to {@see ReviewTemplateRequest} exactly as the editor
 * posts it.
 *
 * A framework made here applies to everyone and is not the default, so it only
 * reaches people no framework covers until it is re-targeted — which waits for
 * a Confirm like every edit that changes what somebody's next appraisal
 * measures. The card says how many people the framework covers today
 * ({@see TemplateResolver}); appraisals already opened keep their snapshot.
 *
 * Deliberately left to the screen: the rating model's bands, editing or
 * archiving a scale (every line measured on it would change at once), removing
 * a section, restoring, and permanent deletion. Opening appraisals in a cycle
 * is the Performance capability's.
 *
 * Everything needs `setup.kpi.view`; changes `setup.kpi.manage`.
 */
class PerformanceFrameworkModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /** How many rows a list returns. */
    private const MAX_RESULTS = 30;

    /** How many items a section read-out spells out. */
    private const MAX_LISTED = 12;

    public function __construct(private readonly PerformanceFrameworkWorkflow $workflow) {}

    public function key(): string
    {
        return 'performance-framework';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.kpi.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_frameworks' => 'findFrameworks',
            'get_framework' => 'getFramework',
            'find_kpi_criteria' => 'findCriteria',
            'find_rating_scales' => 'findScales',
            'find_review_cycles' => 'findCycles',
            'create_framework' => 'createFramework',
            'update_framework' => 'updateFramework',
            'set_framework_item' => 'setItem',
            'remove_framework_item' => 'removeItem',
            'set_framework_section' => 'setSection',
            'set_default_framework' => 'setDefaultFramework',
            'archive_framework' => 'archiveFramework',
            'add_kpi_criterion' => 'addCriterion',
            'update_kpi_criterion' => 'updateCriterion',
            'archive_kpi_criterion' => 'archiveCriterion',
            'create_rating_scale' => 'createScale',
            'set_default_rating_scale' => 'setDefaultScale',
            'create_review_cycle' => 'createCycle',
            'update_review_cycle' => 'updateCycle',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_frameworks' => 'setup.kpi.view',
            'get_framework' => 'setup.kpi.view',
            'find_kpi_criteria' => 'setup.kpi.view',
            'find_rating_scales' => 'setup.kpi.view',
            'find_review_cycles' => 'setup.kpi.view',
            'create_framework' => 'setup.kpi.manage',
            'update_framework' => 'setup.kpi.manage',
            'set_framework_item' => 'setup.kpi.manage',
            'remove_framework_item' => 'setup.kpi.manage',
            'set_framework_section' => 'setup.kpi.manage',
            'set_default_framework' => 'setup.kpi.manage',
            'archive_framework' => 'setup.kpi.manage',
            'add_kpi_criterion' => 'setup.kpi.manage',
            'update_kpi_criterion' => 'setup.kpi.manage',
            'archive_kpi_criterion' => 'setup.kpi.manage',
            'create_rating_scale' => 'setup.kpi.manage',
            'set_default_rating_scale' => 'setup.kpi.manage',
            'create_review_cycle' => 'setup.kpi.manage',
            'update_review_cycle' => 'setup.kpi.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Each changes what somebody's next appraisal measures, or which cycle
        // appraisals are opened in.
        return [
            'update_framework', 'set_framework_item', 'remove_framework_item', 'set_framework_section',
            'set_default_framework', 'archive_framework', 'update_kpi_criterion', 'archive_kpi_criterion',
            'update_review_cycle',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.kpi.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.kpi.manage' ? 'change the performance framework' : 'view the performance framework');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $cycles = $this->allows($user, 'performance.view')
            ? ' Review cycles are listed by list_review_cycles.'
            : ' find_review_cycles lists the review cycles.';

        $manage = $this->allows($user, 'setup.kpi.manage')
            ? <<<'TXT'

            - create_framework makes one by copying another (copy_from) or from catalogue criteria; it applies to everyone and is not the default until update_framework or set_default_framework says otherwise.
            - set_framework_item adds or re-weights an item in a section (a catalogue criterion, or a one-off item by name); remove_framework_item removes one; set_framework_section renames, re-weights or adds a section. update_framework changes the name, description, who it applies to (applies_to all/department/position/employment_type with applies_to_values as names), active flag or result display. These, set_default_framework and archive_framework wait for the user's confirmation.
            - add_kpi_criterion / update_kpi_criterion / archive_kpi_criterion keep the criteria catalogue (the last two wait for confirmation: frameworks measuring a criterion take its wording and scale); create_rating_scale and set_default_rating_scale manage scales; create_review_cycle and update_review_cycle (which waits for confirmation) manage cycles.
            - The rating bands, editing or archiving a scale, removing a section, restoring, and permanent deletion are done on the Performance framework screen (/setup/kpi) — say so if asked.
            TXT
            : '';

        return <<<TXT
        PERFORMANCE FRAMEWORK — the appraisal frameworks people are reviewed against: weighted sections of items, each measured on a rating scale, and who each framework applies to (the most specific wins; among equals, the default). Changing one reaches the next appraisal, never one already opened.
        - find_frameworks lists them; get_framework reads one section by section, with who it covers today. find_kpi_criteria lists the criteria catalogue; find_rating_scales the scales.{$cycles}{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $framework = ['type' => 'STRING', 'description' => 'The framework, by name.'];
        $archived = ['type' => 'BOOLEAN'];

        $tools = [
            ['name' => 'find_frameworks', 'description' => 'List appraisal frameworks: who each applies to, default, active.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['archived' => $archived]]],
            ['name' => 'get_framework', 'description' => 'Read one framework: sections and items with weights and scales, rating bands, who it applies to and covers today.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['framework' => $framework], 'required' => ['framework']]],
            ['name' => 'find_kpi_criteria', 'description' => 'List the criteria catalogue with weights and scales.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['query' => ['type' => 'STRING'], 'archived' => $archived]]],
            ['name' => 'find_rating_scales', 'description' => 'List the rating scales.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
        ];

        if (! $this->allows($user, 'performance.view')) {
            $tools[] = ['name' => 'find_review_cycles', 'description' => 'List review cycles with dates and status.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['archived' => $archived]]];
        }

        $tools = [
            ...$tools,
            [
                'name' => 'create_framework',
                'description' => 'Create a framework by copying another, or from catalogue criteria in one section.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'copy_from' => ['type' => 'STRING', 'description' => 'The framework to copy.'],
                        'criteria' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Catalogue criteria names.'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_framework',
                'description' => "Change a framework's name, description, who it applies to, active flag or result display.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'framework' => $framework,
                        'new_name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'applies_to' => ['type' => 'STRING', 'enum' => ReviewTemplate::APPLIES_TO],
                        'applies_to_values' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Department names, position titles ("Title (Department)" when shared), or employment types.'],
                        'active' => ['type' => 'BOOLEAN'],
                        'result_display' => ['type' => 'STRING', 'enum' => ReviewTemplate::RESULT_DISPLAYS],
                    ],
                    'required' => ['framework'],
                ],
            ],
            [
                'name' => 'set_framework_item',
                'description' => 'Add an item to a framework section, or change an existing item’s weight or section.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'framework' => $framework,
                        'section' => ['type' => 'STRING', 'description' => 'Section name.'],
                        'criterion' => ['type' => 'STRING', 'description' => 'A catalogue criterion.'],
                        'item' => ['type' => 'STRING', 'description' => 'A one-off item name, when not a catalogue criterion.'],
                        'weight' => ['type' => 'NUMBER'],
                    ],
                    'required' => ['framework'],
                ],
            ],
            [
                'name' => 'remove_framework_item',
                'description' => 'Remove an item from a framework.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['framework' => $framework, 'item' => ['type' => 'STRING']], 'required' => ['framework', 'item']],
            ],
            [
                'name' => 'set_framework_section',
                'description' => 'Rename or re-weight a framework section, or add a new one.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'framework' => $framework,
                        'section' => ['type' => 'STRING'],
                        'new_name' => ['type' => 'STRING'],
                        'weight' => ['type' => 'NUMBER'],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['framework', 'section'],
                ],
            ],
            ['name' => 'set_default_framework', 'description' => 'Make a framework the default.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['framework' => $framework], 'required' => ['framework']]],
            ['name' => 'archive_framework', 'description' => 'Archive a framework. Opened appraisals keep it.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['framework' => $framework], 'required' => ['framework']]],
            [
                'name' => 'add_kpi_criterion',
                'description' => 'Add a criterion to the catalogue.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'weight' => ['type' => 'NUMBER', 'description' => 'The weight a framework starts it at.'],
                        'rating_scale' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'weight'],
                ],
            ],
            [
                'name' => 'update_kpi_criterion',
                'description' => 'Change a catalogue criterion.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'criterion' => ['type' => 'STRING'],
                        'new_name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'weight' => ['type' => 'NUMBER'],
                        'rating_scale' => ['type' => 'STRING'],
                        'active' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['criterion'],
                ],
            ],
            ['name' => 'archive_kpi_criterion', 'description' => 'Archive a catalogue criterion. Framework items keep it.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['criterion' => ['type' => 'STRING']], 'required' => ['criterion']]],
            [
                'name' => 'create_rating_scale',
                'description' => 'Create a rating scale: numeric (min, max, step), percentage, or levels (labels, lowest first).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'type' => ['type' => 'STRING', 'enum' => RatingScales::TYPES],
                        'min' => ['type' => 'NUMBER'],
                        'max' => ['type' => 'NUMBER'],
                        'step' => ['type' => 'NUMBER'],
                        'levels' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'description' => ['type' => 'STRING'],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
            ['name' => 'set_default_rating_scale', 'description' => 'Make a rating scale the preferred one.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['scale' => ['type' => 'STRING']], 'required' => ['scale']]],
            [
                'name' => 'create_review_cycle',
                'description' => 'Create a review cycle, as a draft.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING'], 'start_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'], 'end_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.']],
                    'required' => ['name', 'start_date', 'end_date'],
                ],
            ],
            [
                'name' => 'update_review_cycle',
                'description' => "Change a review cycle's name, dates or status (open accepts new appraisals).",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'cycle' => ['type' => 'STRING'],
                        'new_name' => ['type' => 'STRING'],
                        'start_date' => ['type' => 'STRING'],
                        'end_date' => ['type' => 'STRING'],
                        'status' => ['type' => 'STRING', 'enum' => EvaluationPeriod::STATUSES],
                    ],
                    'required' => ['cycle'],
                ],
            ],
        ];

        return $this->permitted($user, $tools);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'framework', 'frameworks', 'appraisal framework', 'performance framework', 'kpi', 'kpis',
            'criteria', 'criterion', 'rating scale', 'rating scales', 'scorecard', 'competencies',
        ];
    }

    /**
     * The frameworks and whom each applies to, in a few lines.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.kpi.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $frameworks = ReviewTemplate::query()->withCount('items')->catalogueOrder()->limit(10)->get();
        $criteria = KpiCriterion::query()->active()->count();
        $scales = RatingScale::query()->count();

        return ContextSection::of('Performance framework', [
            $frameworks->isEmpty()
                ? 'No appraisal frameworks have been set up.'
                : 'Frameworks: '.$frameworks->map(fn (ReviewTemplate $t): string => "{$t->name} ({$this->appliesTo($t)}; {$t->items_count} ".Str::plural('item', (int) $t->items_count).($t->is_default ? '; default' : '').($t->is_active ? '' : '; inactive').')')->implode('; ').'.',
            "{$criteria} active ".Str::plural('criterion', $criteria)." in the catalogue; {$scales} rating ".Str::plural('scale', $scales).'.',
            'The most specific framework covering a person is theirs; among equally specific ones, the default.',
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if (in_array($tool, ['update_kpi_criterion', 'archive_kpi_criterion'], true)) {
            [$criterion] = $this->locateNamed(KpiCriterion::query(), (string) ($args['criterion'] ?? ''), 'criterion');

            if ($criterion === null) {
                return null;
            }

            $lines = ReviewTemplateItem::query()->where('kpi_criterion_id', $criterion->id)->count();

            return $tool === 'archive_kpi_criterion'
                ? "It is measured on {$lines} framework ".Str::plural('line', $lines).'; they keep it. It leaves the catalogue new items are chosen from.'
                : "It is measured on {$lines} framework ".Str::plural('line', $lines).'; appraisals opened from now on take its new wording and scale there, and each line keeps its own weight.';
        }

        if ($tool === 'update_review_cycle') {
            [$cycle] = $this->locateNamed(EvaluationPeriod::query(), (string) ($args['cycle'] ?? ''), 'review cycle');

            if ($cycle === null) {
                return null;
            }

            $opened = $cycle->evaluations()->count();

            return "{$opened} ".Str::plural('appraisal', $opened).' '.($opened === 1 ? 'was' : 'were')." opened in it; it is {$cycle->status} now. Only an open cycle takes new appraisals.";
        }

        if (! in_array($tool, ['update_framework', 'set_framework_item', 'remove_framework_item', 'set_framework_section', 'set_default_framework', 'archive_framework'], true)) {
            return null;
        }

        [$template] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return null;
        }

        $covered = $this->covered($template);

        return match ($tool) {
            'archive_framework' => "It covers {$this->people($covered)} today; their next appraisal falls to another framework, or to none. Appraisals already opened keep it.",
            'set_default_framework' => "It covers {$this->people($covered)} today; as the default it also wins wherever it ties with an equally specific framework.",
            default => "It covers {$this->people($covered)} today; their next appraisal is measured the new way. Appraisals already opened keep their snapshot.",
        };
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findFrameworks(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;

        $cards = ReviewTemplate::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->withCount(['items', 'evaluations'])
            ->catalogueOrder()
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (ReviewTemplate $t): array => $this->frameworkCard($t, 'find', 'neutral', $archived ? 'Archived' : ($t->is_default ? 'Default' : ($t->is_active ? 'Active' : 'Inactive'))))
            ->all();

        return ToolResult::found($archived ? 'Searched archived frameworks' : 'Listed frameworks', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getFramework(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $template->load('items')->loadCount(['items', 'evaluations']);
        $scales = RatingScale::withTrashed()->get()->keyBy('id');
        $criteriaScales = KpiCriterion::withTrashed()->whereKey($template->items->pluck('kpi_criterion_id')->filter())->pluck('rating_scale_id', 'id');

        $sections = collect($template->sectionList())->map(function (array $section) use ($template, $scales, $criteriaScales): string {
            $items = $template->items->filter(fn (ReviewTemplateItem $i): bool => $i->section_key === $section['key']);

            $listed = $items->take(self::MAX_LISTED)->map(function (ReviewTemplateItem $i) use ($template, $scales, $criteriaScales): string {
                $scaleId = $i->rating_scale_id ?? ($i->kpi_criterion_id !== null ? $criteriaScales->get($i->kpi_criterion_id) : null) ?? $template->rating_scale_id;
                $scale = $scaleId !== null ? $scales->get($scaleId) : null;

                return $i->name.' ('.$this->number((float) $i->weight).($scale ? ', '.RatingScales::descriptor($scale->definition()) : '').')';
            });

            return "{$section['name']} ({$this->number($section['weight'])}%): "
                .($items->isEmpty() ? 'nothing yet' : $listed->implode(', ').($items->count() > self::MAX_LISTED ? ' and '.($items->count() - self::MAX_LISTED).' more' : ''));
        });

        $card = $this->frameworkCard($template, 'insight', 'info', $template->is_default ? 'Default' : ($template->is_active ? 'Active' : 'Inactive'));
        $card['meta'] = array_values(array_filter([
            ...$sections->all(),
            'Bands: '.collect($template->bandList())->map(fn (array $b): string => "{$b['label']} from {$this->number($b['min_percent'])}%")->implode(', '),
            'Result shown as '.$template->result_display,
            'Covers '.$this->people($this->covered($template)).' today',
            $template->evaluations_count.' '.Str::plural('appraisal', (int) $template->evaluations_count).' opened with it',
            filled($template->description) ? 'About: '.Str::limit((string) $template->description, 200) : null,
        ]));

        return ToolResult::found("Read {$template->name}", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCriteria(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;
        $query = KpiCriterion::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->with('ratingScale')
            ->withCount('templateItems')
            ->catalogueOrder()
            ->limit(self::MAX_RESULTS);

        $this->whereNameLike($query, (string) ($args['query'] ?? ''));

        $cards = $query->get()->map(fn (KpiCriterion $c): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $archived ? 'Archived' : ($c->is_active ? 'Weight '.$this->number((float) $c->weight) : 'Inactive'),
            title: $c->name,
            subtitle: $c->ratingScale ? $c->ratingScale->name.' ('.RatingScales::descriptor($c->ratingScale->definition()).')' : 'The framework’s scale',
            meta: [
                filled($c->description) ? Str::limit((string) $c->description, 120) : null,
                'On '.$c->template_items_count.' framework '.Str::plural('line', (int) $c->template_items_count),
            ],
            id: $c->hashid,
        ))->all();

        return ToolResult::found($archived ? 'Searched archived criteria' : 'Listed criteria', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findScales(User $user, array $args): ToolResult
    {
        $cards = RatingScale::query()->catalogueOrder()->limit(self::MAX_RESULTS)->get()->map(fn (RatingScale $s): array => $this->scaleCard($s, 'find', 'neutral', $s->is_default ? 'Preferred' : Str::ucfirst($s->type)))->all();

        return ToolResult::found('Listed rating scales', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCycles(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;

        $cards = EvaluationPeriod::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->withCount('evaluations')
            ->recentFirst()
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (EvaluationPeriod $p): array => $this->cycleCard($p, 'find', 'neutral', $archived ? 'Archived' : Str::ucfirst($p->status)))
            ->all();

        return ToolResult::found($archived ? 'Searched archived review cycles' : 'Listed review cycles', count($cards).' found', $cards);
    }

    // ── Frameworks ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createFramework(User $user, array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        $criteriaNames = array_values(array_filter((array) ($args['criteria'] ?? []), fn ($n): bool => is_string($n) && trim($n) !== ''));
        $copyFrom = trim((string) ($args['copy_from'] ?? ''));

        if (($copyFrom === '') === ($criteriaNames === [])) {
            return ToolResult::error('Created the framework', 'Say which framework to copy, or which catalogue criteria it measures — one or the other.');
        }

        if ($copyFrom !== '') {
            [$source, $error] = $this->locateNamed(ReviewTemplate::query(), $copyFrom, 'framework');

            if ($source === null) {
                return ToolResult::error('Created the framework', $error);
            }

            $document = [...$this->document($source->load('items')), 'description' => $source->description];
        } else {
            $items = [];

            foreach ($criteriaNames as $criterionName) {
                [$criterion, $error] = $this->locateNamed(KpiCriterion::query()->active(), $criterionName, 'criterion');

                if ($criterion === null) {
                    return ToolResult::error('Created the framework', '“'.Str::limit($criterionName, 60).'”: '.$error);
                }

                $items[] = ['kpi_criterion_id' => $criterion->id, 'rating_scale_id' => null, 'section_key' => 'overall', 'name' => $criterion->name, 'description' => $criterion->description, 'weight' => (float) $criterion->weight];
            }

            $document = [
                'rating_scale_id' => RatingScale::query()->where('is_default', true)->value('id'),
                'result_display' => 'band',
                'sections' => [ReviewTemplate::fallbackSection()],
                'bands' => RatingModel::defaultBands(),
                'items' => $items,
            ];
        }

        // Everyone, and not the default: by the resolver's own order it then
        // reaches only people no framework covers, until it is re-targeted.
        $document = [
            ...$document,
            'name' => $name,
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : ($document['description'] ?? null),
            'applies_to' => 'all',
            'applies_to_values' => null,
            'is_default' => false,
            'is_active' => true,
        ];

        if (($problem = $this->nameTaken(ReviewTemplate::query(), $name, 'framework')) !== null) {
            return ToolResult::error('Created the framework', $problem);
        }

        [$template, $problem] = $this->save(null, $document);

        if ($template === null) {
            return ToolResult::error('Created the framework', $problem);
        }

        $covered = $this->covered($template);

        return ToolResult::ok(
            "Created {$template->name}",
            'It applies to everyone and is not the default, so it covers '.$this->people($covered).' no other framework does. Re-target it with update_framework.',
            $this->frameworkCard($template->loadCount(['items', 'evaluations']), 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateFramework(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $document = $this->document($template->load('items'));
        $changed = [];

        if (filled($args['new_name'] ?? null)) {
            $document['name'] = trim((string) $args['new_name']);
            $changed[] = 'name';

            if (($problem = $this->nameTaken(ReviewTemplate::query(), $document['name'], 'framework', $template)) !== null) {
                return ToolResult::error('Updated the framework', $problem);
            }
        }

        if (filled($args['description'] ?? null)) {
            $document['description'] = trim((string) $args['description']);
            $changed[] = 'description';
        }

        if (is_bool($args['active'] ?? null)) {
            $document['is_active'] = $args['active'];
            $changed[] = $args['active'] ? 'made active' : 'made inactive';
        }

        if (filled($args['result_display'] ?? null)) {
            $document['result_display'] = (string) $args['result_display'];
            $changed[] = 'result display';
        }

        if (filled($args['applies_to'] ?? null) || filled($args['applies_to_values'] ?? null)) {
            $appliesTo = (string) ($args['applies_to'] ?? $template->applies_to);
            [$values, $error] = $this->eligibility($appliesTo, (array) ($args['applies_to_values'] ?? []));

            if ($error !== null) {
                return ToolResult::error('Updated the framework', $error);
            }

            $document['applies_to'] = $appliesTo;
            $document['applies_to_values'] = $values;
            $changed[] = 'who it applies to';
        }

        if ($changed === []) {
            return ToolResult::error('Updated the framework', 'Say what to change: its name, description, who it applies to, whether it is active, or how its result is shown.');
        }

        [$saved, $problem] = $this->save($template, $document);

        if ($saved === null) {
            return ToolResult::error('Updated the framework', $problem);
        }

        return ToolResult::ok("Updated {$saved->name}", Str::ucfirst(implode(', ', $changed)).'.', $this->frameworkCard($saved->loadCount(['items', 'evaluations']), 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setItem(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $document = $this->document($template->load('items'));
        $criterion = null;

        if (filled($args['criterion'] ?? null)) {
            [$criterion, $error] = $this->locateNamed(KpiCriterion::query()->active(), (string) $args['criterion'], 'criterion');

            if ($criterion === null) {
                return ToolResult::error('Set the framework item', $error);
            }

            $index = collect($document['items'])->search(fn (array $i): bool => $i['kpi_criterion_id'] === $criterion->id);
        } elseif (filled($args['item'] ?? null)) {
            $wanted = Str::lower(trim((string) $args['item']));
            $index = collect($document['items'])->search(fn (array $i): bool => Str::lower($i['name']) === $wanted);
        } else {
            return ToolResult::error('Set the framework item', 'Say which criterion or item.');
        }

        $existing = $index === false ? null : $document['items'][$index];

        [$sectionKey, $error] = $this->sectionKey($document, $args['section'] ?? null, $existing['section_key'] ?? null);

        if ($sectionKey === null) {
            return ToolResult::error('Set the framework item', $error);
        }

        $weight = isset($args['weight']) ? (float) $args['weight'] : ($existing['weight'] ?? ($criterion !== null ? (float) $criterion->weight : null));

        if ($weight === null) {
            return ToolResult::error('Set the framework item', 'Give the item a weight.');
        }

        $item = [
            ...($existing ?? [
                'kpi_criterion_id' => $criterion?->id,
                'rating_scale_id' => null,
                'name' => $criterion?->name ?? trim((string) $args['item']),
                'description' => $criterion?->description,
            ]),
            'section_key' => $sectionKey,
            'weight' => $weight,
        ];

        if ($existing === null) {
            $document['items'][] = $item;
        } else {
            $document['items'][$index] = $item;
        }

        [$saved, $problem] = $this->save($template, $document);

        if ($saved === null) {
            return ToolResult::error('Set the framework item', $problem);
        }

        $section = collect($saved->sectionList())->firstWhere('key', $sectionKey)['name'] ?? $sectionKey;

        return ToolResult::ok(
            ($existing === null ? 'Added ' : 'Updated ').$item['name'].' in '.$saved->name,
            "{$section}, weight {$this->number($weight)}.",
            $this->frameworkCard($saved->loadCount(['items', 'evaluations']), $existing === null ? 'add' : 'edit', 'info', $existing === null ? 'Item added' : 'Item updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeItem(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $document = $this->document($template->load('items'));
        $wanted = Str::lower(trim((string) ($args['item'] ?? '')));
        $items = collect($document['items']);
        $matches = $items->filter(fn (array $i): bool => Str::lower($i['name']) === $wanted);

        if ($matches->isEmpty()) {
            $matches = $items->filter(fn (array $i): bool => $wanted !== '' && str_contains(Str::lower($i['name']), $wanted));
        }

        if ($matches->count() !== 1) {
            return ToolResult::error('Removed the framework item', $matches->isEmpty()
                ? "{$template->name} has no item matching “".Str::limit((string) ($args['item'] ?? ''), 60).'”.'
                : 'More than one item matches: '.$matches->pluck('name')->implode(', ').'.');
        }

        $name = $matches->first()['name'];
        $document['items'] = $items->forget($matches->keys()->first())->values()->all();

        [$saved, $problem] = $this->save($template, $document);

        if ($saved === null) {
            return ToolResult::error('Removed the framework item', $problem);
        }

        return ToolResult::ok("Removed {$name} from {$saved->name}", null, $this->frameworkCard($saved->loadCount(['items', 'evaluations']), 'cancel', 'warning', 'Item removed'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setSection(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $document = $this->document($template->load('items'));
        $wanted = Str::lower(trim((string) ($args['section'] ?? '')));
        $index = collect($document['sections'])->search(fn (array $s): bool => Str::lower($s['name']) === $wanted);

        if ($index === false) {
            if (! isset($args['weight'])) {
                return ToolResult::error('Set the framework section', "{$template->name} has no section called “".Str::limit((string) ($args['section'] ?? ''), 60).'”. To add one, give it a weight. Its sections: '.collect($document['sections'])->pluck('name')->implode(', ').'.');
            }

            $document['sections'][] = [
                'key' => '',
                'name' => trim((string) ($args['new_name'] ?? $args['section'])),
                'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
                'weight' => (float) $args['weight'],
            ];
            $verb = 'Added';
        } else {
            if (! filled($args['new_name'] ?? null) && ! isset($args['weight']) && ! filled($args['description'] ?? null)) {
                return ToolResult::error('Set the framework section', 'Say what to change: its name, weight or description.');
            }

            $section = $document['sections'][$index];
            $document['sections'][$index] = [
                ...$section,
                'name' => filled($args['new_name'] ?? null) ? trim((string) $args['new_name']) : $section['name'],
                'weight' => isset($args['weight']) ? (float) $args['weight'] : $section['weight'],
                'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : $section['description'],
            ];
            $verb = 'Updated';
        }

        [$saved, $problem] = $this->save($template, $document);

        if ($saved === null) {
            return ToolResult::error('Set the framework section', $problem);
        }

        $total = array_sum(array_column($saved->sectionList(), 'weight'));

        return ToolResult::ok(
            "{$verb} a section of {$saved->name}",
            'Sections now weigh '.$this->number((float) $total).'% in all.',
            $this->frameworkCard($saved->loadCount(['items', 'evaluations']), 'edit', 'info', 'Section '.Str::lower($verb)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setDefaultFramework(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        if ($template->is_default) {
            return ToolResult::error('Set the default framework', "{$template->name} is already the default.");
        }

        $this->workflow->setDefaultFramework($template, ' via assistant');

        return ToolResult::ok("Made {$template->name} the default", null, $this->frameworkCard($template->loadCount(['items', 'evaluations']), 'edit', 'info', 'Default'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveFramework(User $user, array $args): ToolResult
    {
        [$template, $error] = $this->locateNamed(ReviewTemplate::query(), (string) ($args['framework'] ?? ''), 'framework');

        if ($template === null) {
            return ToolResult::error('Looked up the framework', $error);
        }

        $card = $this->frameworkCard($template->loadCount(['items', 'evaluations']), 'archive', 'warning', 'Archived');

        $this->workflow->archiveFramework($template, ' via assistant');

        return ToolResult::ok("Archived {$template->name}", 'Appraisals already opened keep it; it can be restored on the screen.', $card);
    }

    // ── Criteria, scales and cycles ──────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function addCriterion(User $user, array $args): ToolResult
    {
        [$scaleId, $error] = $this->scaleArgument($args);

        if ($error !== null) {
            return ToolResult::error('Added the criterion', $error);
        }

        $data = [
            'name' => trim((string) ($args['name'] ?? '')),
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'weight' => $args['weight'] ?? null,
            'rating_scale_id' => $scaleId,
            'is_active' => true,
        ];

        if (($problem = $this->invalid($data, (new KpiCriterionRequest)->rules()) ?? $this->nameTaken(KpiCriterion::query(), $data['name'], 'criterion')) !== null) {
            return ToolResult::error('Added the criterion', $problem);
        }

        $criterion = $this->workflow->saveCriterion(null, $data, ' via assistant');

        return ToolResult::ok("Added {$criterion->name} to the catalogue", 'Weight '.$this->number((float) $criterion->weight).'.', $this->criterionCard($criterion, 'add', 'positive', 'Added'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateCriterion(User $user, array $args): ToolResult
    {
        [$criterion, $error] = $this->locateNamed(KpiCriterion::query(), (string) ($args['criterion'] ?? ''), 'criterion');

        if ($criterion === null) {
            return ToolResult::error('Looked up the criterion', $error);
        }

        [$scaleId, $error] = $this->scaleArgument($args);

        if ($error !== null) {
            return ToolResult::error('Updated the criterion', $error);
        }

        $changes = array_filter([
            'name' => filled($args['new_name'] ?? null) ? trim((string) $args['new_name']) : null,
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'weight' => $args['weight'] ?? null,
            'rating_scale_id' => $scaleId,
        ], fn ($v): bool => $v !== null);

        if (is_bool($args['active'] ?? null)) {
            $changes['is_active'] = $args['active'];
        }

        if ($changes === []) {
            return ToolResult::error('Updated the criterion', 'Say what to change: its name, description, weight, scale, or whether it is offered.');
        }

        $merged = ['name' => $criterion->name, 'description' => $criterion->description, 'weight' => $criterion->weight, 'rating_scale_id' => $criterion->rating_scale_id, 'is_active' => (bool) $criterion->is_active, ...$changes];
        $problem = $this->invalid($merged, (new KpiCriterionRequest)->rules())
            ?? (isset($changes['name']) ? $this->nameTaken(KpiCriterion::query(), $changes['name'], 'criterion', $criterion) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the criterion', $problem);
        }

        $this->workflow->saveCriterion($criterion, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$criterion->name}",
            'Appraisals opened from now on take its wording and scale on every framework measuring it; each framework keeps its own weight for it.',
            $this->criterionCard($criterion, 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveCriterion(User $user, array $args): ToolResult
    {
        [$criterion, $error] = $this->locateNamed(KpiCriterion::query(), (string) ($args['criterion'] ?? ''), 'criterion');

        if ($criterion === null) {
            return ToolResult::error('Looked up the criterion', $error);
        }

        $card = $this->criterionCard($criterion, 'archive', 'warning', 'Archived');

        $this->workflow->archiveCriterion($criterion, ' via assistant');

        return ToolResult::ok("Archived {$criterion->name}", 'Framework items measuring it keep it.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createScale(User $user, array $args): ToolResult
    {
        $labels = array_values(array_filter((array) ($args['levels'] ?? []), fn ($l): bool => is_string($l) && trim($l) !== ''));

        $data = RatingScaleRequest::normalise([
            'name' => trim((string) ($args['name'] ?? '')),
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'type' => (string) ($args['type'] ?? 'numeric'),
            'min' => $args['min'] ?? null,
            'max' => $args['max'] ?? null,
            'step' => $args['step'] ?? 1,
            'levels' => array_map(fn (string $label, int $index): array => ['label' => trim($label), 'value' => $index + 1], $labels, array_keys($labels)),
            'is_default' => false,
        ]);

        $request = new RatingScaleRequest;

        if (($problem = $this->invalid($data, $request->rules(), $request->messages()) ?? $this->nameTaken(RatingScale::query(), $data['name'], 'rating scale')) !== null) {
            return ToolResult::error('Created the rating scale', $problem);
        }

        $scale = $this->workflow->saveScale(null, $data, ' via assistant');

        return ToolResult::ok("Created {$scale->name}", RatingScales::descriptor($scale->definition()).'.', $this->scaleCard($scale, 'add', 'positive', 'Created'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setDefaultScale(User $user, array $args): ToolResult
    {
        [$scale, $error] = $this->locateNamed(RatingScale::query(), (string) ($args['scale'] ?? ''), 'rating scale');

        if ($scale === null) {
            return ToolResult::error('Looked up the rating scale', $error);
        }

        if ($scale->is_default) {
            return ToolResult::error('Set the preferred scale', "{$scale->name} is already the preferred scale.");
        }

        $this->workflow->saveScale($scale, ['is_default' => true], ' via assistant');

        return ToolResult::ok("Made {$scale->name} the preferred scale", 'New criteria and frameworks start from it.', $this->scaleCard($scale, 'edit', 'info', 'Preferred'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function createCycle(User $user, array $args): ToolResult
    {
        $start = $this->isoDate($args['start_date'] ?? null);
        $end = $this->isoDate($args['end_date'] ?? null);

        if ($start === null || $end === null) {
            return ToolResult::error('Created the review cycle', 'Give the dates as YYYY-MM-DD.');
        }

        $data = ['name' => trim((string) ($args['name'] ?? '')), 'start_date' => $start, 'end_date' => $end, 'status' => 'draft'];

        if (($problem = $this->invalid($data, (new EvaluationPeriodRequest)->rules()) ?? $this->nameTaken(EvaluationPeriod::query(), $data['name'], 'review cycle')) !== null) {
            return ToolResult::error('Created the review cycle', $problem);
        }

        $cycle = $this->workflow->savePeriod(null, $data, ' via assistant');

        return ToolResult::ok("Created {$cycle->name}", 'It is a draft; open it when appraisals should start.', $this->cycleCard($cycle, 'add', 'positive', 'Draft'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateCycle(User $user, array $args): ToolResult
    {
        [$cycle, $error] = $this->locateNamed(EvaluationPeriod::query(), (string) ($args['cycle'] ?? ''), 'review cycle');

        if ($cycle === null) {
            return ToolResult::error('Looked up the review cycle', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (filled($args[$field] ?? null)) {
                $date = $this->isoDate($args[$field]);

                if ($date === null) {
                    return ToolResult::error('Updated the review cycle', 'Give the dates as YYYY-MM-DD.');
                }

                $changes[$field] = $date;
            }
        }

        if (filled($args['status'] ?? null)) {
            $changes['status'] = (string) $args['status'];
        }

        if ($changes === []) {
            return ToolResult::error('Updated the review cycle', 'Say what to change: its name, dates or status.');
        }

        $merged = ['name' => $cycle->name, 'start_date' => $cycle->start_date?->toDateString(), 'end_date' => $cycle->end_date?->toDateString(), 'status' => $cycle->status, ...$changes];
        $problem = $this->invalid($merged, (new EvaluationPeriodRequest)->rules())
            ?? (isset($changes['name']) ? $this->nameTaken(EvaluationPeriod::query(), $changes['name'], 'review cycle', $cycle) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the review cycle', $problem);
        }

        $this->workflow->savePeriod($cycle, $changes, ' via assistant');

        return ToolResult::ok("Updated {$cycle->name}", null, $this->cycleCard($cycle->loadCount('evaluations'), 'edit', 'info', Str::ucfirst($cycle->status)));
    }

    // ── Framework documents ──────────────────────────────────────────────────

    /**
     * A framework as the editor posts it: every section, band and item, so an
     * edit of one line rebuilds and re-validates the whole.
     *
     * @return array<string, mixed>
     */
    private function document(ReviewTemplate $template): array
    {
        return [
            'name' => $template->name,
            'description' => $template->description,
            'rating_scale_id' => $template->rating_scale_id,
            'result_display' => $template->result_display,
            'applies_to' => $template->applies_to,
            'applies_to_values' => $template->applies_to_values,
            'is_default' => (bool) $template->is_default,
            'is_active' => (bool) $template->is_active,
            'sections' => $template->sectionList(),
            'bands' => $template->bandList(),
            'items' => $template->items->map(fn (ReviewTemplateItem $i): array => [
                'kpi_criterion_id' => $i->kpi_criterion_id,
                'rating_scale_id' => $i->rating_scale_id,
                'section_key' => $i->section_key,
                'name' => $i->name,
                'description' => $i->description,
                'weight' => (float) $i->weight,
            ])->values()->all(),
        ];
    }

    /**
     * Normalise, validate and save a framework document the way the editor's
     * request does, in its words.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: ReviewTemplate|null, 1: string|null}
     */
    private function save(?ReviewTemplate $template, array $document): array
    {
        $data = ReviewTemplateRequest::normalise($document);

        $problem = $this->invalid(
            $data,
            ReviewTemplateRequest::documentRules(),
            (new ReviewTemplateRequest)->messages(),
            ['items.*.weight' => 'weight', 'sections.*.weight' => 'section weight', 'sections.*.name' => 'section name', 'items.*.name' => 'item name'],
            fn (Validator $validator) => ReviewTemplateRequest::validateDocument($validator, $data),
        );

        if ($problem !== null) {
            return [null, $problem];
        }

        return [$this->workflow->saveFramework($template, $data, ' via assistant'), null];
    }

    /**
     * The key of the section an item goes in: the one named, else the one it
     * is in, else the only one there is.
     *
     * @param  array<string, mixed>  $document
     * @return array{0: string|null, 1: string}
     */
    private function sectionKey(array $document, mixed $named, ?string $current): array
    {
        $sections = collect($document['sections']);

        if (filled($named)) {
            $wanted = Str::lower(trim((string) $named));
            $section = $sections->first(fn (array $s): bool => Str::lower($s['name']) === $wanted);

            return $section !== null
                ? [$section['key'], '']
                : [null, 'No section called “'.Str::limit((string) $named, 60).'”. Its sections: '.$sections->pluck('name')->implode(', ').'.'];
        }

        if ($current !== null) {
            return [$current, ''];
        }

        return $sections->count() === 1
            ? [$sections->first()['key'], '']
            : [null, 'Say which section: '.$sections->pluck('name')->implode(', ').'.'];
    }

    /**
     * Who a framework applies to, as the ids or values the editor stores — or
     * why a name is not one.
     *
     * @param  list<mixed>  $names
     * @return array{0: list<string>|null, 1: string|null}
     */
    private function eligibility(string $appliesTo, array $names): array
    {
        $names = array_values(array_filter(array_map(fn ($n): string => trim(is_scalar($n) ? (string) $n : ''), $names), fn (string $n): bool => $n !== ''));

        if ($appliesTo === 'all') {
            return [null, null];
        }

        if ($names === []) {
            return [null, (new ReviewTemplateRequest)->messages()['applies_to_values.required_unless']];
        }

        $values = [];

        foreach ($names as $name) {
            $value = match ($appliesTo) {
                'department' => Department::query()->where(fn (Builder $q) => $q->whereRaw('lower(name) = ?', [Str::lower($name)])->orWhereRaw('lower(code) = ?', [Str::lower($name)]))->value('id'),
                'employment_type' => in_array($type = Str::snake(str_replace('-', ' ', Str::lower($name))), StoreEmployeeRequest::EMPLOYMENT_TYPES, true) ? $type : null,
                default => $this->positionId($name),
            };

            if (is_string($value) && str_starts_with($value, 'ambiguous:')) {
                return [null, Str::after($value, 'ambiguous:')];
            }

            if ($value === null) {
                return [null, match ($appliesTo) {
                    'department' => 'No department is called “'.Str::limit($name, 60).'”.',
                    'employment_type' => '“'.Str::limit($name, 60).'” is not an employment type: '.implode(', ', StoreEmployeeRequest::EMPLOYMENT_TYPES).'.',
                    default => 'No position is called “'.Str::limit($name, 60).'”.',
                }];
            }

            $values[] = (string) $value;
        }

        return [array_values(array_unique($values)), null];
    }

    /**
     * A position by its title, or "Title (Department)" when several share it.
     * An ambiguous title comes back as "ambiguous:<why>".
     */
    private function positionId(string $name): int|string|null
    {
        $department = null;

        if (preg_match('/^(.*)\(([^)]+)\)\s*$/', $name, $m) === 1) {
            [$name, $department] = [trim($m[1]), trim($m[2])];
        }

        $matches = Position::query()
            ->with('department:id,name')
            ->whereRaw('lower(title) = ?', [Str::lower($name)])
            ->when($department !== null, fn (Builder $q) => $q->whereHas('department', fn (Builder $d) => $d->whereRaw('lower(name) = ?', [Str::lower($department)])))
            ->get();

        return match ($matches->count()) {
            0 => null,
            1 => $matches->first()->id,
            default => 'ambiguous:More than one position is called “'.Str::limit($name, 60).'”: '.$matches->map(fn (Position $p): string => "{$name} ({$p->department?->name})")->implode(', ').'. Say which, e.g. “'.$name.' ('.$matches->first()->department?->name.')”.',
        };
    }

    /**
     * How many people whose days are counted a framework is the one for today —
     * asked of {@see TemplateResolver}, so "covers" means what the next launch
     * would pick, not what the rule matches.
     */
    private function covered(ReviewTemplate $template): int
    {
        $resolver = app(TemplateResolver::class);
        $frameworks = $resolver->active();

        return Employee::query()
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->get(['id', 'department_id', 'position_id', 'employment_type'])
            ->filter(fn (Employee $e): bool => $resolver->forEmployee($e, $frameworks)?->id === $template->id)
            ->count();
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one record by name — exact first, then a partial name only one
     * has.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{0: TModel|null, 1: string}
     */
    private function locateNamed(Builder $query, string $needle, string $noun): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, "Say which {$noun}."];
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $matches = $this->whereNameLike(clone $query, $needle)->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, "No {$noun} matches “".Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, "More than one {$noun} matches “".Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * The scale a `rating_scale` argument names, as an id — or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: int|null, 1: string|null}
     */
    private function scaleArgument(array $args): array
    {
        if (! filled($args['rating_scale'] ?? null)) {
            return [null, null];
        }

        [$scale, $error] = $this->locateNamed(RatingScale::query(), (string) $args['rating_scale'], 'rating scale');

        return $scale !== null ? [$scale->id, null] : [null, $error.' The scales: '.$this->catalog(RatingScale::query()->orderBy('name')->pluck('name')).'.'];
    }

    /**
     * Refuse a name another live record of the kind has in any case. The
     * screen does not insist; here it keeps two from answering to one name.
     *
     * @param  Builder<*>  $query
     */
    private function nameTaken(Builder $query, string $name, string $noun, ?Model $except = null): ?string
    {
        $taken = (clone $query)
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        return $taken ? "There is already a {$noun} called “{$name}”." : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
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
     * Who a framework applies to, in words.
     */
    private function appliesTo(ReviewTemplate $template): string
    {
        $values = array_map('strval', (array) ($template->applies_to_values ?? []));

        return match ($template->applies_to) {
            'department' => 'departments: '.Department::withTrashed()->whereIn('id', $values)->orderBy('name')->pluck('name')->implode(', '),
            'position' => 'positions: '.Position::query()->whereIn('id', $values)->orderBy('title')->pluck('title')->implode(', '),
            'employment_type' => 'employment types: '.implode(', ', array_map(fn (string $v): string => str_replace('_', ' ', $v), $values)),
            default => 'everyone',
        };
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function people(int $count): string
    {
        return $count.' '.Str::plural('person', $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkCard(ReviewTemplate $template, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $template->name,
            subtitle: 'Applies to '.$this->appliesTo($template),
            meta: [
                isset($template->items_count) ? $template->items_count.' '.Str::plural('item', (int) $template->items_count).' in '.count($template->sectionList()).' '.Str::plural('section', count($template->sectionList())) : null,
                isset($template->evaluations_count) ? $template->evaluations_count.' '.Str::plural('appraisal', (int) $template->evaluations_count).' opened' : null,
            ],
            id: $template->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function criterionCard(KpiCriterion $criterion, string $kind, string $tone, string $badge): array
    {
        $scale = $criterion->ratingScale()->first();

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $criterion->name,
            subtitle: 'Weight '.$this->number((float) $criterion->weight).($scale ? ' · '.$scale->name : ''),
            meta: [filled($criterion->description) ? Str::limit((string) $criterion->description, 140) : null],
            id: $criterion->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scaleCard(RatingScale $scale, string $kind, string $tone, string $badge): array
    {
        $definition = $scale->definition();

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $scale->name,
            subtitle: RatingScales::descriptor($definition),
            meta: [
                $definition['levels'] !== null ? 'Levels: '.collect($definition['levels'])->pluck('label')->implode(', ') : null,
                filled($scale->description) ? Str::limit((string) $scale->description, 120) : null,
            ],
            id: $scale->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cycleCard(EvaluationPeriod $cycle, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $cycle->name,
            subtitle: ($cycle->start_date?->format('M j, Y') ?? '?').' – '.($cycle->end_date?->format('M j, Y') ?? '?'),
            meta: [isset($cycle->evaluations_count) ? $cycle->evaluations_count.' '.Str::plural('appraisal', (int) $cycle->evaluations_count) : null],
            id: $cycle->hashid,
        );
    }
}
