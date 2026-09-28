<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Recruitment\StoreRecruitmentPipelineRequest;
use App\Models\JobApplication;
use App\Models\RecruitmentPipeline;
use App\Models\RecruitmentPipelineStage;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Recruitment\PipelineException;
use App\Support\Recruitment\PipelineWorkflow;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recruitment Pipelines capability (ADR 0029): the hiring processes a job
 * posting runs on — a named, ordered list of stages, each in progress, the one
 * "hired" stage, or a "rejected" one.
 *
 * **Reading** answers "what pipelines do we have?", "what stages does Warehouse
 * Hiring have, and how many candidates sit in each?". **Doing** creates a
 * pipeline (from steps, or by copying one), renames it or makes it the default,
 * and adds, renames, moves or removes a stage, through {@see PipelineWorkflow}
 * — the screen's own path — against the screen's own rules
 * ({@see StoreRecruitmentPipelineRequest}: exactly one hired stage, at least
 * one rejected).
 *
 * A stage keeps its kind once it exists: turning an in-progress stage into a
 * rejected one would reclassify every candidate on it, so that is done on the
 * screen, deliberately. Moving candidates is the Recruitment capability's.
 *
 * Removing a stage and deleting a pipeline wait for the user's Confirm
 * (ADR 0049), and the card says what runs on it. Everything needs
 * `recruitment.configure-pipelines`, the screen's own permission.
 */
class RecruitmentPipelinesModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const PERMISSION = 'recruitment.configure-pipelines';

    /** How many postings a read-out names. */
    private const MAX_LISTED = 10;

    public function __construct(private readonly PipelineWorkflow $workflow) {}

    public function key(): string
    {
        return 'recruitment-pipelines';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    protected function toolMap(): array
    {
        return [
            'find_pipelines' => 'findPipelines',
            'get_pipeline' => 'getPipeline',
            'create_pipeline' => 'createPipeline',
            'update_pipeline' => 'updatePipeline',
            'set_pipeline_stage' => 'setStage',
            'remove_pipeline_stage' => 'removeStage',
            'delete_pipeline' => 'deletePipeline',
        ];
    }

    protected function permissionMap(): array
    {
        return array_fill_keys(array_keys($this->toolMap()), self::PERMISSION);
    }

    protected function confirmTools(): array
    {
        // Removing a stage changes the board every posting on the pipeline
        // shows; deleting a pipeline cannot be undone.
        return ['remove_pipeline_stage', 'delete_pipeline'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot(self::PERMISSION)) {
            return $this->denied('configure recruitment pipelines');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        RECRUITMENT PIPELINES — the hiring processes job postings run on: ordered stages, each in progress, the one hired stage, or a rejected one. The default is what new postings start with.
        - find_pipelines lists them; get_pipeline reads one stage by stage, with how many candidates sit in each and which postings run on it.
        - create_pipeline makes one from its in-progress steps (plus a hired stage and rejected stages, "Hired" and "Rejected" unless named), or by copying one. update_pipeline renames one or makes it the default.
        - set_pipeline_stage adds a stage (in progress or rejected, placed after a named stage), or renames or moves one. remove_pipeline_stage and delete_pipeline wait for the user's confirmation; neither is possible while candidates sit on the stage or postings run on the pipeline.
        - Changing an existing stage's kind is done on the Recruitment Pipelines screen (/setup/recruitment-pipelines). Moving candidates between stages is the recruitment capability.
        TXT;
    }

    public function tools(User $user): array
    {
        $pipeline = ['type' => 'STRING', 'description' => 'The pipeline, by name.'];

        return $this->permitted($user, [
            ['name' => 'find_pipelines', 'description' => 'List recruitment pipelines with their stages and how many postings run on each.', 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => 'get_pipeline', 'description' => 'Read one pipeline stage by stage, with candidates per stage and the postings on it.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['pipeline' => $pipeline], 'required' => ['pipeline']]],
            [
                'name' => 'create_pipeline',
                'description' => 'Create a pipeline from its in-progress steps, or by copying one.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'steps' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'The in-progress stages, in order.'],
                        'hired_stage' => ['type' => 'STRING', 'description' => 'Default "Hired".'],
                        'rejected_stages' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Default ["Rejected"].'],
                        'copy_from' => $pipeline,
                        'make_default' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_pipeline',
                'description' => 'Rename a pipeline or make it the default.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['pipeline' => $pipeline, 'new_name' => ['type' => 'STRING'], 'make_default' => ['type' => 'BOOLEAN']],
                    'required' => ['pipeline'],
                ],
            ],
            [
                'name' => 'set_pipeline_stage',
                'description' => 'Add a stage to a pipeline, or rename or move one.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'pipeline' => $pipeline,
                        'stage' => ['type' => 'STRING', 'description' => 'The stage, by name.'],
                        'new_name' => ['type' => 'STRING'],
                        'kind' => ['type' => 'STRING', 'enum' => ['open', 'lost'], 'description' => 'For a new stage: open (in progress) or lost (rejected).'],
                        'after' => ['type' => 'STRING', 'description' => 'Place it after this stage.'],
                        'first' => ['type' => 'BOOLEAN', 'description' => 'Place it first.'],
                    ],
                    'required' => ['pipeline', 'stage'],
                ],
            ],
            [
                'name' => 'remove_pipeline_stage',
                'description' => 'Remove a stage no candidate sits on.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['pipeline' => $pipeline, 'stage' => ['type' => 'STRING']], 'required' => ['pipeline', 'stage']],
            ],
            ['name' => 'delete_pipeline', 'description' => 'Delete a pipeline no posting runs on.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['pipeline' => $pipeline], 'required' => ['pipeline']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['pipeline', 'pipelines', 'hiring process', 'hiring processes', 'hiring stages', 'recruitment stages'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot(self::PERMISSION) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $pipelines = RecruitmentPipeline::query()->with('stages')->withCount('postings')->orderByDesc('is_default')->orderBy('name')->get();

        return ContextSection::of('Recruitment pipelines', $pipelines->isEmpty()
            ? ['No recruitment pipelines have been set up.']
            : $pipelines->map(fn (RecruitmentPipeline $p): string => $p->name.($p->is_default ? ' (default)' : '').": {$this->stageLine($p)}; {$p->postings_count} ".Str::plural('posting', (int) $p->postings_count).'.')->all());
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        [$pipeline] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return null;
        }

        $postings = $pipeline->postings()->count();

        if ($tool === 'delete_pipeline') {
            return $postings === 0
                ? 'No posting runs on it. It cannot be brought back once deleted.'
                : "{$postings} ".Str::plural('posting', $postings).' run on it, so it will be refused.';
        }

        if ($tool !== 'remove_pipeline_stage') {
            return null;
        }

        [$stage] = $this->locateStage($pipeline, (string) ($args['stage'] ?? ''));
        $sitting = $stage !== null ? JobApplication::query()->where('recruitment_pipeline_stage_id', $stage->id)->count() : 0;

        return "{$postings} ".Str::plural('posting', $postings).' run on this pipeline; the stage leaves every one of their boards. '
            .($sitting > 0 ? "{$sitting} ".Str::plural('candidate', $sitting).' sit on it, so it will be refused.' : 'No candidate sits on it.');
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPipelines(User $user, array $args): ToolResult
    {
        $cards = RecruitmentPipeline::query()->with('stages')->withCount('postings')->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (RecruitmentPipeline $p): array => $this->pipelineCard($p, 'find', 'neutral', $p->is_default ? 'Default' : Str::plural('posting', (int) $p->postings_count).': '.$p->postings_count))
            ->all();

        return ToolResult::found('Listed recruitment pipelines', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getPipeline(User $user, array $args): ToolResult
    {
        [$pipeline, $error] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return ToolResult::error('Looked up the pipeline', $error);
        }

        $pipeline->load('stages')->loadCount('postings');
        $sitting = JobApplication::query()
            ->whereIn('recruitment_pipeline_stage_id', $pipeline->stages->pluck('id'))
            ->selectRaw('recruitment_pipeline_stage_id, count(*) as total')
            ->groupBy('recruitment_pipeline_stage_id')
            ->pluck('total', 'recruitment_pipeline_stage_id');
        $postings = $pipeline->postings()->orderBy('title')->limit(self::MAX_LISTED + 1)->pluck('title');

        $card = $this->pipelineCard($pipeline, 'insight', 'info', $pipeline->is_default ? 'Default' : 'Pipeline');
        $card['meta'] = [
            ...$pipeline->stages->map(fn (RecruitmentPipelineStage $s): string => ($s->position + 1).". {$s->name} ({$this->kindWord($s->kind)}) — ".((int) ($sitting[$s->id] ?? 0)).' '.Str::plural('candidate', (int) ($sitting[$s->id] ?? 0)))->all(),
            $postings->isEmpty()
                ? 'No posting runs on it'
                : 'Postings: '.$postings->take(self::MAX_LISTED)->implode(', ').($pipeline->postings_count > self::MAX_LISTED ? ' and '.($pipeline->postings_count - self::MAX_LISTED).' more' : ''),
        ];

        return ToolResult::found("Read {$pipeline->name}", null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createPipeline(User $user, array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        $steps = $this->names($args['steps'] ?? []);
        $copyFrom = trim((string) ($args['copy_from'] ?? ''));

        if (($copyFrom === '') === ($steps === [])) {
            return ToolResult::error('Created the pipeline', 'Give its in-progress steps, or a pipeline to copy — one or the other.');
        }

        if ($copyFrom !== '') {
            [$source, $error] = $this->locate($copyFrom);

            if ($source === null) {
                return ToolResult::error('Created the pipeline', $error);
            }

            $stages = $source->stages->map(fn (RecruitmentPipelineStage $s): array => ['name' => $s->name, 'kind' => $s->kind])->all();
        } else {
            $hired = trim((string) ($args['hired_stage'] ?? '')) ?: 'Hired';
            $rejected = $this->names($args['rejected_stages'] ?? []) ?: ['Rejected'];

            $stages = [
                ...array_map(fn (string $n): array => ['name' => $n, 'kind' => 'open'], $steps),
                ['name' => $hired, 'kind' => 'won'],
                ...array_map(fn (string $n): array => ['name' => $n, 'kind' => 'lost'], $rejected),
            ];
        }

        $data = ['name' => $name, 'is_default' => ($args['make_default'] ?? false) === true, 'stages' => $stages];

        if (($problem = $this->invalidPipeline($data, null) ?? $this->nameTaken($name)) !== null) {
            return ToolResult::error('Created the pipeline', $problem);
        }

        $pipeline = $this->workflow->create($name, $data['is_default'], $stages, ' via assistant');

        return ToolResult::ok("Created {$pipeline->name}", $pipeline->is_default ? 'It is the default for new postings.' : null, $this->pipelineCard($pipeline->load('stages')->loadCount('postings'), 'add', 'positive', 'Created'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updatePipeline(User $user, array $args): ToolResult
    {
        [$pipeline, $error] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return ToolResult::error('Looked up the pipeline', $error);
        }

        $name = filled($args['new_name'] ?? null) ? trim((string) $args['new_name']) : $pipeline->name;
        $default = ($args['make_default'] ?? false) === true || $pipeline->is_default;

        if ($name === $pipeline->name && $default === (bool) $pipeline->is_default) {
            return ToolResult::error('Updated the pipeline', ($args['make_default'] ?? false) === true ? "{$pipeline->name} is already the default." : 'Say what to change: its name, or make it the default.');
        }

        $problem = $this->invalid(['name' => $name], ['name' => StoreRecruitmentPipelineRequest::rulesFor($pipeline)['name']])
            ?? ($name !== $pipeline->name ? $this->nameTaken($name, $pipeline) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the pipeline', $problem);
        }

        $this->workflow->update($pipeline, $name, $default, null, ' via assistant');

        return ToolResult::ok("Updated {$pipeline->name}", $default ? 'It is the default for new postings.' : null, $this->pipelineCard($pipeline->load('stages')->loadCount('postings'), 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setStage(User $user, array $args): ToolResult
    {
        [$pipeline, $error] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return ToolResult::error('Looked up the pipeline', $error);
        }

        $stages = $this->currentStages($pipeline);
        [$existing] = $this->locateStage($pipeline, (string) ($args['stage'] ?? ''), exactOnly: true);

        if ($existing !== null) {
            $index = collect($stages)->search(fn (array $s): bool => $s['id'] === $existing->id);
            $row = $stages[$index];
            array_splice($stages, $index, 1);

            if (filled($args['new_name'] ?? null)) {
                $row['name'] = trim((string) $args['new_name']);
            }

            if (filled($args['kind'] ?? null) && $args['kind'] !== $existing->kind) {
                return ToolResult::error('Set the stage', "“{$existing->name}” is already {$this->kindWord($existing->kind)}; a stage's kind is changed on the Recruitment Pipelines screen, since every candidate on it would change with it.");
            }

            $verb = 'Updated';
        } else {
            $row = ['id' => null, 'name' => trim((string) ($args['stage'] ?? '')), 'kind' => (string) ($args['kind'] ?? 'open')];
            $index = null;
            $verb = 'Added';
        }

        [$position, $error] = $this->position($stages, $args, $index, $row['kind']);

        if ($error !== null) {
            return ToolResult::error('Set the stage', $error);
        }

        array_splice($stages, $position, 0, [$row]);

        if (($duplicate = $this->duplicateStage($stages)) !== null) {
            return ToolResult::error('Set the stage', "{$pipeline->name} already has a stage called “{$duplicate}”.");
        }

        return $this->saveStages($pipeline, $stages, "{$verb} “{$row['name']}” in {$pipeline->name}", 'Set the stage');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeStage(User $user, array $args): ToolResult
    {
        [$pipeline, $error] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return ToolResult::error('Looked up the pipeline', $error);
        }

        [$stage, $error] = $this->locateStage($pipeline, (string) ($args['stage'] ?? ''));

        if ($stage === null) {
            return ToolResult::error('Removed the stage', $error);
        }

        $stages = array_values(array_filter($this->currentStages($pipeline), fn (array $s): bool => $s['id'] !== $stage->id));

        return $this->saveStages($pipeline, $stages, "Removed “{$stage->name}” from {$pipeline->name}", 'Removed the stage');
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deletePipeline(User $user, array $args): ToolResult
    {
        [$pipeline, $error] = $this->locate((string) ($args['pipeline'] ?? ''));

        if ($pipeline === null) {
            return ToolResult::error('Looked up the pipeline', $error);
        }

        $card = $this->pipelineCard($pipeline->load('stages')->loadCount('postings'), 'archive', 'warning', 'Deleted');

        try {
            $this->workflow->delete($pipeline, ' via assistant');
        } catch (PipelineException $e) {
            return ToolResult::error('Deleted the pipeline', $e->getMessage());
        }

        $default = RecruitmentPipeline::query()->where('is_default', true)->value('name');

        return ToolResult::ok("Deleted {$card['title']}", $default !== null ? "The default is {$default}." : null, $card);
    }

    // ── Stages ───────────────────────────────────────────────────────────────

    /**
     * The pipeline's stages as the editor posts them — each kept one with its id.
     *
     * @return list<array{id: int|null, name: string, kind: string}>
     */
    private function currentStages(RecruitmentPipeline $pipeline): array
    {
        return $pipeline->stages()->get()->map(fn (RecruitmentPipelineStage $s): array => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind])->values()->all();
    }

    /**
     * Where a stage goes: first, after a named stage, back where it was, or —
     * for a new one — after the last stage of its kind group (a new in-progress
     * stage before the hired one; a new rejected one at the end).
     *
     * @param  list<array<string, mixed>>  $stages  Without the stage being placed.
     * @param  array<string, mixed>  $args
     * @return array{0: int, 1: string|null}
     */
    private function position(array $stages, array $args, ?int $was, string $kind): array
    {
        if (($args['first'] ?? false) === true) {
            return [0, null];
        }

        if (filled($args['after'] ?? null)) {
            $wanted = Str::lower(trim((string) $args['after']));
            $after = collect($stages)->search(fn (array $s): bool => Str::lower($s['name']) === $wanted);

            return $after === false
                ? [0, 'No stage called “'.Str::limit((string) $args['after'], 60).'”. The stages: '.collect($stages)->pluck('name')->implode(', ').'.']
                : [$after + 1, null];
        }

        if ($was !== null) {
            return [$was, null];
        }

        $lastOpen = collect($stages)->filter(fn (array $s): bool => $s['kind'] === 'open')->keys()->last();

        return [$kind === 'open' ? ($lastOpen === null ? 0 : $lastOpen + 1) : count($stages), null];
    }

    /**
     * Validate a stage list by the screen's rules and save it.
     *
     * @param  list<array<string, mixed>>  $stages
     */
    private function saveStages(RecruitmentPipeline $pipeline, array $stages, string $label, string $failure): ToolResult
    {
        if (($problem = $this->invalidPipeline(['name' => $pipeline->name, 'is_default' => (bool) $pipeline->is_default, 'stages' => $stages], $pipeline)) !== null) {
            return ToolResult::error($failure, $problem);
        }

        try {
            $this->workflow->update($pipeline, $pipeline->name, (bool) $pipeline->is_default, $stages, ' via assistant');
        } catch (PipelineException $e) {
            return ToolResult::error($failure, $e->getMessage());
        }

        return ToolResult::ok($label, $this->stageLine($pipeline->load('stages')), $this->pipelineCard($pipeline->loadCount('postings'), 'edit', 'info', 'Stages updated'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function invalidPipeline(array $data, ?RecruitmentPipeline $pipeline): ?string
    {
        return $this->invalid(
            $data,
            StoreRecruitmentPipelineRequest::rulesFor($pipeline),
            (new StoreRecruitmentPipelineRequest)->messages(),
            ['stages.*.name' => 'stage name'],
            fn (Validator $validator) => StoreRecruitmentPipelineRequest::validateStages($validator, (array) $data['stages']),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    private function duplicateStage(array $stages): ?string
    {
        $seen = [];

        foreach ($stages as $stage) {
            $key = Str::lower($stage['name']);

            if (isset($seen[$key])) {
                return $stage['name'];
            }

            $seen[$key] = true;
        }

        return null;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one pipeline by name — exact first, then a partial name only one
     * has.
     *
     * @return array{0: RecruitmentPipeline|null, 1: string}
     */
    private function locate(string $needle): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which pipeline.'];
        }

        $matches = RecruitmentPipeline::query()->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $matches = RecruitmentPipeline::query()->search(addcslashes($needle, '%_\\'))->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, 'No pipeline matches “'.Str::limit($needle, 60).'”. The pipelines: '.$this->catalog(RecruitmentPipeline::query()->orderBy('name')->pluck('name')).'.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one pipeline matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * One stage of a pipeline by name — exact, or a partial name only one
     * stage has (unless only an exact match will do).
     *
     * @return array{0: RecruitmentPipelineStage|null, 1: string}
     */
    private function locateStage(RecruitmentPipeline $pipeline, string $needle, bool $exactOnly = false): array
    {
        $needle = Str::lower(trim($needle));
        $stages = $pipeline->stages()->get();
        $matches = $stages->filter(fn (RecruitmentPipelineStage $s): bool => Str::lower($s->name) === $needle);

        if ($matches->isEmpty() && ! $exactOnly && $needle !== '') {
            $matches = $stages->filter(fn (RecruitmentPipelineStage $s): bool => str_contains(Str::lower($s->name), $needle));
        }

        return match (true) {
            $matches->count() === 1 => [$matches->first(), ''],
            $matches->isEmpty() => [null, "{$pipeline->name} has no stage called “".Str::limit($needle, 60).'”. Its stages: '.$stages->pluck('name')->implode(', ').'.'],
            default => [null, 'More than one stage matches: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * Refuse a name another pipeline has in any case, so two never answer to
     * one name here or in the recruitment capability.
     */
    private function nameTaken(string $name, ?RecruitmentPipeline $except = null): ?string
    {
        $taken = RecruitmentPipeline::query()
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? "There is already a pipeline called “{$name}”." : null;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $list): array
    {
        return array_values(array_filter(
            array_map(fn ($n): string => trim(is_scalar($n) ? (string) $n : ''), (array) $list),
            fn (string $n): bool => $n !== '',
        ));
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    private function kindWord(string $kind): string
    {
        return match ($kind) {
            'won' => 'hired',
            'lost' => 'rejected',
            default => 'in progress',
        };
    }

    /**
     * "Applied → Screening → Interview → Hired | rejected: Rejected".
     */
    private function stageLine(RecruitmentPipeline $pipeline): string
    {
        /** @var Collection<int, RecruitmentPipelineStage> $stages */
        $stages = $pipeline->stages;
        $flow = $stages->whereIn('kind', ['open', 'won'])->pluck('name')->implode(' → ');
        $lost = $stages->where('kind', 'lost')->pluck('name');

        return $flow.($lost->isNotEmpty() ? ' | rejected: '.$lost->implode(', ') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineCard(RecruitmentPipeline $pipeline, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $pipeline->name,
            subtitle: $this->stageLine($pipeline),
            meta: [
                isset($pipeline->postings_count) ? $pipeline->postings_count.' '.Str::plural('posting', (int) $pipeline->postings_count) : null,
                $pipeline->is_default ? 'Default for new postings' : null,
            ],
            id: $pipeline->hashid,
        );
    }
}
