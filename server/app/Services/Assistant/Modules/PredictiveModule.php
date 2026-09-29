<?php

namespace App\Services\Assistant\Modules;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LocalModel;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Ml\Graduation\GraduationException;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlClient;
use App\Support\Ml\MlException;
use App\Support\Ml\PredictionWording;
use App\Support\Ml\UnassessedEmployees;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the three Predictive Workforce Analytics capabilities share (ADR 0058):
 * Attrition Risk, Promotion Readiness and Performance Forecast each keep runs —
 * a model's scores for every active employee at one moment — and each can move
 * from the general model to one trained on the organisation's own records
 * (ADR 0046).
 *
 * Each surface gets the same eight tools, named for it: a summary of the latest
 * run, the ranked roster (by tier, department, name — or who the model
 * declined), one person's score and what is behind it, running a new one,
 * deleting one, and the model's graduation: where it stands, training the
 * organisation's own, and switching between the two. All of it goes through the
 * screens' own classes — the assessor or forecaster, {@see ModelGraduation} —
 * and the screens' own permissions (`analytics.<surface>.view` to read,
 * `.manage` for everything else).
 *
 * A score is a statistical signal about a person, so three rules hold on every
 * surface: reading a named person's score is audited as `viewed` (ADR 0027);
 * the stored runs are read, never the live model, so a read never waits on the
 * inference service; and deleting a run or switching the model waits for the
 * user's Confirm (ADR 0049), with the card saying what the page would show.
 */
abstract class PredictiveModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    protected const CHANNEL = ' via assistant';

    /** How many people a list returns at most. */
    private const MAX_ROWS = 15;

    /** The employee columns a score is shown with. */
    private const EMPLOYEE = 'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id';

    public function __construct(
        protected readonly ModelGraduation $graduation,
        protected readonly MlClient $ml,
    ) {}

    // ── What a surface supplies ──────────────────────────────────────────────

    /** ModelGraduation's key: attrition, promotion or performance. */
    abstract protected function surface(): string;

    /** "assessment" or "forecast". */
    abstract protected function noun(): string;

    /** What the page is about, for descriptions: "attrition risk". */
    abstract protected function subject(): string;

    /**
     * Tool names by role: summary, find, get, run, delete, status, train, switch.
     *
     * @return array<string, string>
     */
    abstract protected function names(): array;

    /** @return Builder<Model> The surface's runs. */
    abstract protected function runs(): Builder;

    /** @return HasMany<Model, Model> A run's per-employee scores. */
    abstract protected function scoresOf(Model $run): HasMany;

    /** The score column a tier or band lives in. */
    abstract protected function tierColumn(): string;

    /** @return array<string, string> Tier key => the page's label, in the page's order. */
    abstract protected function tiers(): array;

    /** @return array<string, int> Tier label => how many a run put there. */
    abstract protected function counts(Model $run): array;

    /** @return list<string> The run's headline beyond counts: averages, the period. */
    abstract protected function headline(Model $run): array;

    /** @return list<string|null> A score's lines in a list. */
    abstract protected function rowMeta(Model $score, Model $run): array;

    /** @return list<string|null> A score read in full. */
    abstract protected function detail(Model $score, Model $run, ?Model $previous): array;

    /** Run the surface's assessor or forecaster. @throws MlException */
    abstract protected function execute(User $user): Model;

    /** Delete a run through the surface's own class. */
    abstract protected function forget(Model $run): void;

    /** Whether the model declines people (and the run lists them). */
    protected function declines(): bool
    {
        return false;
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    protected function viewPermission(): string
    {
        return "analytics.{$this->surface()}.view";
    }

    protected function managePermission(): string
    {
        return "analytics.{$this->surface()}.manage";
    }

    public function isAvailable(User $user): bool
    {
        return $user->can($this->viewPermission());
    }

    protected function toolMap(): array
    {
        $names = $this->names();

        return [
            $names['summary'] => 'summary',
            $names['find'] => 'find',
            $names['get'] => 'get',
            $names['status'] => 'status',
            $names['run'] => 'runNow',
            $names['delete'] => 'delete',
            $names['train'] => 'train',
            $names['switch'] => 'switchModel',
        ];
    }

    protected function readTools(): array
    {
        return [$this->names()['status']];
    }

    protected function permissionMap(): array
    {
        return collect($this->toolMap())
            ->map(fn (string $method, string $tool): string => $this->isReadOnly($tool) ? $this->viewPermission() : $this->managePermission())
            ->all();
    }

    protected function confirmTools(): array
    {
        // Both change what the page shows everybody from now on.
        return [$this->names()['delete'], $this->names()['switch']];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot($this->viewPermission()) || $user->cannot($this->permissionMap()[$tool])) {
            return $this->denied('do that with '.$this->subject());
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function tools(User $user): array
    {
        $names = $this->names();
        $noun = $this->noun();
        $find = [
            'tier' => ['type' => 'STRING', 'enum' => array_keys($this->tiers())],
            'department' => ['type' => 'STRING', 'description' => 'Department name or code.'],
            'search' => ['type' => 'STRING', 'description' => 'A name or employee number.'],
        ];

        if ($this->declines()) {
            $find['declined'] = ['type' => 'BOOLEAN', 'description' => 'List the people the model declined, with why, instead.'];
        }

        return $this->permitted($user, [
            ['name' => $names['summary'], 'description' => "The latest {$noun} of {$this->subject()}: when, whose model, counts, averages, change since the one before.", 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => $names['find'], 'description' => "People in the latest {$noun}, highest first, optionally by tier, department or name.", 'parameters' => ['type' => 'OBJECT', 'properties' => $find]],
            ['name' => $names['get'], 'description' => "One person's {$this->subject()} in the latest {$noun}, and what is behind it.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => ['type' => 'STRING', 'description' => 'Full name or employee number.']], 'required' => ['employee']]],
            ['name' => $names['status'], 'description' => "Whether {$this->subject()} uses the general model or the organisation's own, and what training its own still needs.", 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => $names['run'], 'description' => "Run a new {$noun} for every active employee.", 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => $names['delete'], 'description' => "Delete a past {$noun}.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['run' => ['type' => 'STRING', 'description' => '"latest", "previous", or the date it ran (YYYY-MM-DD).']], 'required' => ['run']]],
            ['name' => $names['train'], 'description' => "Train the organisation's own model on its records, once every requirement is met. It is checked, not switched to.", 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass]],
            ['name' => $names['switch'], 'description' => "Switch to the organisation's own model (the newest that passed its check) or back to the general one.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['to' => ['type' => 'STRING', 'enum' => ['own', 'general']]], 'required' => ['to']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot($this->viewPermission()) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $run = $this->runs()->latest('id')->first();

        // Aggregates only: a named person's score is read by the tool, and audited.
        return ContextSection::of(Str::headline($this->subject()), $run === null
            ? ["No {$this->noun()} has been run yet."]
            : [$this->when($run), $this->countLine($run), ...$this->headline($run)]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        $names = $this->names();

        if ($tool === $names['delete']) {
            [$run] = $this->locateRun((string) ($args['run'] ?? ''));

            if ($run === null) {
                return null;
            }

            $newest = $this->runs()->latest('id')->first();
            $next = $this->runs()->whereKeyNot($run->getKey())->latest('id')->first();

            return "It deletes the {$this->noun()} of {$this->date($run)}, with the scores of {$this->people((int) $run->employees_scored)}; they cannot be recovered."
                .($newest?->is($run) ? ($next === null ? " No other {$this->noun()} would be left." : " The page would show the one of {$this->date($next)} instead.") : '');
        }

        if ($tool === $names['switch']) {
            if (($args['to'] ?? null) === 'own') {
                $candidate = $this->candidate();

                return $candidate === null ? null
                    : "From the next {$this->noun()}, scores would come from your organisation's own model, trained on {$candidate->examples} of your records on {$this->date($candidate)}. Past {$this->noun()}s keep the model that scored them.";
            }

            return "From the next {$this->noun()}, scores would come from the general model again. Your own model is kept, and can be switched back to only by training again.";
        }

        return null;
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    protected function summary(User $user, array $args): ToolResult
    {
        $run = $this->runs()->latest('id')->first();

        if ($run === null) {
            return ToolResult::found("Read the latest {$this->noun()}", "No {$this->noun()} has been run yet.", []);
        }

        $previous = $this->runs()->whereKeyNot($run->getKey())->latest('id')->first();

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: Str::ucfirst($this->noun()),
            title: $this->people((int) $run->employees_scored).' scored',
            subtitle: $this->when($run),
            meta: [
                $this->countLine($run),
                ...$this->headline($run),
                $this->declinedLine($run),
                $previous !== null ? 'Since the one of '.$this->date($previous).': '.$this->delta($run, $previous) : null,
            ],
        );

        return ToolResult::found("Read the latest {$this->noun()}", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function find(User $user, array $args): ToolResult
    {
        $run = $this->runs()->latest('id')->first();

        if ($run === null) {
            return ToolResult::error("Read the latest {$this->noun()}", "No {$this->noun()} has been run yet.");
        }

        if (($args['declined'] ?? false) === true && $this->declines()) {
            return $this->declined($run);
        }

        $query = $this->scoresOf($run)->with([self::EMPLOYEE, 'employee.department:id,name', 'employee.position:id,title'])->ranked();
        $filters = [];

        if (filled($args['tier'] ?? null) && isset($this->tiers()[$args['tier']])) {
            $query->where($this->tierColumn(), $args['tier']);
            $filters[] = $this->tiers()[$args['tier']];
        }

        if (filled($args['department'] ?? null)) {
            $needle = Str::lower(trim((string) $args['department']));
            $department = Department::query()->where(fn (Builder $q) => $q->whereRaw('lower(name) = ?', [$needle])->orWhereRaw('lower(code) = ?', [$needle]))->first();

            if ($department === null) {
                return ToolResult::error('Looked up the department', 'No department is called “'.Str::limit(trim((string) $args['department']), 60).'”.');
            }

            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $department->id));
            $filters[] = $department->name;
        }

        if (filled($args['search'] ?? null)) {
            $query->whereHas('employee', fn (Builder $q) => $this->matchByTokens($q, (string) $args['search']));
        }

        $total = (clone $query)->count();
        $cards = $query->limit(self::MAX_ROWS)->get()
            ->filter(fn (Model $score): bool => $score->employee !== null)
            ->map(fn (Model $score): array => $this->scoreCard($score, $run))
            ->values()
            ->all();

        return ToolResult::found(
            'Listed the latest '.$this->noun().($filters !== [] ? ' — '.implode(', ', $filters) : ''),
            $total === 0 ? 'Nobody' : ($total > count($cards) ? "{$total} people; the first ".count($cards).' shown' : "{$total} ".Str::plural('person', $total)),
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function get(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $run = $this->runs()->latest('id')->first();

        if ($run === null) {
            return ToolResult::error("Read the latest {$this->noun()}", "No {$this->noun()} has been run yet.");
        }

        // A named person's score was read — ADR 0027's audit rule.
        ActivityLogger::log(
            event: 'viewed',
            description: "Viewed the {$this->subject()} of {$employee->full_name} via assistant",
            subject: $employee,
            logName: ModelGraduation::SURFACES[$this->surface()]['log'],
            subjectLabel: $employee->full_name,
        );

        $score = $this->scoresOf($run)->where('employee_id', $employee->id)->with([self::EMPLOYEE, 'employee.department:id,name', 'employee.position:id,title'])->first();

        if ($score === null) {
            $reason = collect($run->unassessed ?? [])->firstWhere('employee_id', $employee->id)['reason'] ?? null;

            return ToolResult::found("Read {$employee->full_name}", null, [$this->card(
                kind: 'insight',
                tone: 'neutral',
                badge: $reason !== null ? 'Not scored' : 'Not in it',
                title: $employee->full_name,
                subtitle: $this->when($run),
                meta: [$reason !== null
                    ? 'The model declined them: '.Str::lcfirst(PredictionWording::UNASSESSED[$reason][0] ?? $reason).' — '.(PredictionWording::UNASSESSED[$reason][1] ?? '').'.'
                    : "They were not in the latest {$this->noun()} (only active employees are scored). A new one would include them if they are active."],
                id: $employee->id,
            )]);
        }

        $previousRun = $this->runs()->whereKeyNot($run->getKey())->latest('id')->first();
        $previous = $previousRun === null ? null : $this->scoresOf($previousRun)->where('employee_id', $employee->id)->first();

        $card = $this->scoreCard($score, $run, 'insight', 'info');
        $card['meta'] = array_values(array_filter([...$this->detail($score, $run, $previous), $this->when($run)]));

        return ToolResult::found("Read {$employee->full_name}", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function status(User $user, array $args): ToolResult
    {
        $ready = isset($this->ml->health()['models'][$this->surface()]);
        $check = $this->graduation->check($this->surface(), $ready);
        $unmet = collect($check['requirements'])->reject(fn (array $r): bool => $r['status'] === 'met');

        $card = $this->card(
            kind: 'insight',
            tone: $check['stage'] === 'graduated' ? 'positive' : 'info',
            badge: match ($check['stage']) {
                'graduated' => 'Own model',
                'collecting' => 'Collecting',
                default => 'General model',
            },
            title: $check['stage'] === 'graduated' ? "Using your organisation's own model" : 'Using the general model',
            subtitle: "{$check['met_count']} of {$check['total_count']} requirements met",
            meta: [
                $check['active'] !== null ? 'In use since '.OrganizationClock::local(Carbon::parse((string) $check['active']['activated_at']))->format('M j, Y').", trained on {$check['active']['examples']} of your records" : null,
                ...$unmet->take(5)->map(fn (array $r): string => 'Still needed — '.UntrustedText::clean($r['label'], 120).': '.$this->progress($r))->all(),
                $check['latest'] !== null ? 'Latest training: '.($check['latest']['status'] === 'ready' ? 'passed its check — ready to switch to' : 'did not pass its check').' ('.$check['latest']['examples'].' records)' : null,
                $ready ? null : 'The prediction service is not reachable right now: no new run or training can start.',
            ],
        );

        return ToolResult::found('Checked the model', null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    protected function runNow(User $user, array $args): ToolResult
    {
        try {
            $run = $this->execute($user);
        } catch (MlException $e) {
            // Written for the person who asked; details stay in the log.
            return ToolResult::error("Ran a new {$this->noun()}", $e->getMessage());
        }

        $declined = count($run->unassessed ?? []);

        return ToolResult::ok(
            "Ran a new {$this->noun()}",
            $this->people((int) $run->employees_scored).' scored'.($declined > 0 ? "; {$declined} left out (no completed appraisal)" : '').'.',
            $this->card(kind: 'add', tone: 'positive', badge: 'New', title: $this->people((int) $run->employees_scored).' scored', subtitle: $this->when($run), meta: [$this->countLine($run), ...$this->headline($run)]),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function delete(User $user, array $args): ToolResult
    {
        [$run, $error] = $this->locateRun((string) ($args['run'] ?? ''));

        if ($run === null) {
            return ToolResult::error("Deleted the {$this->noun()}", $error);
        }

        $date = $this->date($run);
        $this->forget($run);

        return ToolResult::ok("Deleted the {$this->noun()} of {$date}");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function train(User $user, array $args): ToolResult
    {
        try {
            $attempt = $this->graduation->train($this->surface(), $user, self::CHANNEL);
        } catch (GraduationException|MlException $e) {
            return ToolResult::error('Trained your own model', $e->getMessage());
        }

        return $attempt->status === 'ready'
            ? ToolResult::ok('Trained your own model', "It passed its check on {$attempt->examples} of your records. Nothing has switched yet — review it on the page, or ask to switch to it.")
            : ToolResult::ok('Trained your own model', "It did not pass its check, so nothing has changed. The {$this->subject()} page says why.");
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function switchModel(User $user, array $args): ToolResult
    {
        try {
            if (($args['to'] ?? null) === 'own') {
                $candidate = $this->candidate();

                if ($candidate === null) {
                    return ToolResult::error('Switched the model', 'There is no trained model that passed its check to switch to. Train one first.');
                }

                $this->graduation->activate($candidate, $user, self::CHANNEL);

                return ToolResult::ok("Switched to your organisation's own model", "Run a new {$this->noun()} to score everyone with it.");
            }

            $this->graduation->revert($this->surface(), $user, self::CHANNEL);
        } catch (GraduationException $e) {
            return ToolResult::error('Switched the model', $e->getMessage());
        }

        return ToolResult::ok('Switched back to the general model', "Run a new {$this->noun()} to rescore everyone with it.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A score as a card: the person, their tier, the surface's lines.
     *
     * @return array<string, mixed>
     */
    protected function scoreCard(Model $score, Model $run, string $kind = 'find', string $tone = 'neutral'): array
    {
        /** @var Employee $employee */
        $employee = $score->employee;
        $tier = (string) $score->{$this->tierColumn()};

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $this->tiers()[$tier] ?? Str::headline($tier),
            title: $employee->full_name,
            subtitle: implode(' · ', array_filter([$employee->position?->title, $employee->department?->name])) ?: $employee->employee_no,
            meta: $this->rowMeta($score, $run),
            avatar: ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
            id: $employee->id,
        );
    }

    /**
     * A factor's source label, as the model service named it — cleaned, because
     * it reaches the model and the chat.
     */
    protected function label(mixed $label): string
    {
        return UntrustedText::clean(is_scalar($label) ? (string) $label : null, 80) ?? 'an input';
    }

    /**
     * "Ran 3 days ago (Sep 26, 2026) by Ana Cruz, with the general model".
     */
    protected function when(Model $run): string
    {
        $by = $run->generated_by !== null ? User::withTrashed()->find($run->generated_by)?->full_name : null;

        return 'Ran '.$run->created_at?->diffForHumans().' ('.$this->date($run).')'
            .($by !== null ? ' by '.UntrustedText::clean($by, 80) : '')
            .', with '.($run->local_model_id !== null ? "your organisation's own model" : 'the general model');
    }

    protected function date(Model $model): string
    {
        return $model->created_at === null ? 'an unknown date' : OrganizationClock::local($model->created_at)->format('M j, Y');
    }

    private function people(int $n): string
    {
        return $n.' '.Str::plural('person', $n);
    }

    private function countLine(Model $run): string
    {
        return collect($this->counts($run))->map(fn (int $n, string $label): string => "{$label} {$n}")->implode(', ');
    }

    private function declinedLine(Model $run): ?string
    {
        $declined = collect($run->unassessed ?? []);

        if (! $this->declines() || $declined->isEmpty()) {
            return null;
        }

        return 'Not scored: '.$declined->countBy('reason')
            ->map(fn (int $n, string $reason): string => $n.' — '.Str::lcfirst(PredictionWording::UNASSESSED[$reason][0] ?? $reason))
            ->implode('; ');
    }

    private function delta(Model $run, Model $previous): string
    {
        $before = $this->counts($previous);

        return collect($this->counts($run))
            ->map(function (int $n, string $label) use ($before): string {
                $change = $n - ($before[$label] ?? 0);

                return $label.' '.($change === 0 ? 'unchanged' : ($change > 0 ? '+' : '').$change);
            })
            ->implode(', ');
    }

    /**
     * The people the latest run left out, and what would include them.
     */
    private function declined(Model $run): ToolResult
    {
        $cards = collect(UnassessedEmployees::resolve($run->unassessed))
            ->take(self::MAX_ROWS)
            ->map(fn (array $row): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: 'Not scored',
                title: $row['employee']['full_name'],
                subtitle: implode(' · ', array_filter([$row['employee']['position'], $row['employee']['department']])) ?: $row['employee']['employee_no'],
                meta: [
                    PredictionWording::UNASSESSED[$row['reason']][0] ?? $row['reason'],
                    Str::ucfirst(PredictionWording::UNASSESSED[$row['reason']][1] ?? ''),
                ],
                id: $row['employee']['id'],
            ))
            ->all();

        $total = count($run->unassessed ?? []);

        return ToolResult::found("Listed who the latest {$this->noun()} left out", $total === 0 ? 'Nobody' : "{$total} ".Str::plural('person', $total), $cards);
    }

    /**
     * Exactly one run — "latest", "previous", or the date it ran on the
     * organisation's clock — or why not.
     *
     * @return array{0: Model|null, 1: string}
     */
    private function locateRun(string $which): array
    {
        $which = Str::lower(trim($which));

        if (in_array($which, ['latest', 'previous'], true)) {
            $run = $this->runs()->latest('id')->skip($which === 'previous' ? 1 : 0)->first();

            return [$run, $run === null ? "There is no {$which} {$this->noun()}." : ''];
        }

        $date = $this->isoDate($which);

        if ($date === null) {
            return [null, 'Say which: "latest", "previous", or the date it ran (YYYY-MM-DD).'];
        }

        $runs = $this->runs()
            ->whereBetween('created_at', [OrganizationClock::at($date, '00:00:00'), OrganizationClock::at($date, '23:59:59')])
            ->get();

        return match ($runs->count()) {
            0 => [null, "No {$this->noun()} ran on {$date}."],
            1 => [$runs->first(), ''],
            default => [null, "{$runs->count()} {$this->noun()}s ran on {$date}. Delete the one you mean from the page."],
        };
    }

    /** The newest trained model that passed its check and is not in use. */
    private function candidate(): ?LocalModel
    {
        return LocalModel::query()->forModel($this->surface())->where('status', 'ready')->latest('id')->first();
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function progress(array $requirement): string
    {
        if ($requirement['format'] === 'check') {
            return 'not yet';
        }

        if ($requirement['format'] === 'percent') {
            return PredictionWording::number((float) $requirement['current']).'% of the '.PredictionWording::number((float) $requirement['required']).'% needed';
        }

        $unit = (int) $requirement['required'] === 1 ? ($requirement['unit_one'] ?: $requirement['unit']) : $requirement['unit'];

        return PredictionWording::number((float) $requirement['current']).' of '.PredictionWording::number((float) $requirement['required']).($unit !== '' ? " {$unit}" : '');
    }
}
