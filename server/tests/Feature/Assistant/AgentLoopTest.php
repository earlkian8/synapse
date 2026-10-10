<?php

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Permission;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Security\PendingActions;
use App\Support\Ai\GeminiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/*
| The agent loop (ADR 0068 §1, §3, §5, §7, §8): the model keeps going until the
| request is done; held writes become one plan; the mode decides what waits.
*/

function loopAnalystPosting(): JobPosting
{
    seedDefaultPipeline();

    return JobPosting::factory()->create(['title' => 'Cybersecurity Analyst', 'status' => 'open']);
}

function loopRecruiter(): void
{
    actingAsUserWith(['recruitment.view', 'recruitment.create', 'recruitment.update', 'recruitment.manage-pipeline']);
}

function loopAddAna(): array
{
    return modelCall('add_application', ['posting' => 'Cybersecurity Analyst', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@example.com', 'source' => 'other']);
}

function loopMoveAna(): array
{
    return modelCall('move_application', ['applicant' => 'Ana Cruz', 'stage' => 'Offer']);
}

/** A tiny PDF as the model would receive it inline. */
function loopPdfPart(): array
{
    return ['mime' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 stub')];
}

test('the reported turn: a lookup no longer ends the turn — the model goes on to act', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([
        [modelCall('find_job_postings', ['query' => 'cybersecurity'])],
        [loopAddAna()],
        [loopMoveAna()],
        [['text' => 'Ana Cruz is in Cybersecurity Analyst at Offer.']],
    ]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'put Ana Cruz (ana@example.com) in cybersecurity analyst as an offer');

    $application = JobApplication::with('pipelineStage')->whereHas('applicant', fn ($q) => $q->where('first_name', 'Ana'))->first();

    expect($application?->pipelineStage->name)->toBe('Offer')
        ->and($turn['reply'])->toBe('Ana Cruz is in Cybersecurity Analyst at Offer.')
        ->and(collect($turn['steps'])->pluck('status')->all())->toBe(['done', 'done', 'done']);
});

test('a write repeated by the model runs once', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([[loopAddAna()], [loopAddAna()], [loopAddAna()]]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'add Ana Cruz to the cybersecurity analyst posting');

    expect(Applicant::where('first_name', 'Ana')->count())->toBe(1)
        ->and(JobApplication::count())->toBe(1)
        ->and($turn['reply'])->toContain('Ana Cruz');
});

test('with a document attached, the whole request becomes one plan and nothing runs', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([[loopAddAna()], [loopMoveAna()], [['text' => 'All done!']]]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'put this person in cybersecurity analyst as an offer', [], [loopPdfPart()]);

    $cards = collect($turn['actions'])->where('kind', 'confirm');

    expect($cards)->toHaveCount(1)
        ->and($cards->first()['plan'])->toHaveCount(2)
        ->and($cards->first()['plan'][1]['title'])->toBe('Move application')
        ->and(Applicant::count())->toBe(0)
        ->and($turn['reply'])->toContain('Nothing has changed yet')
        ->and($turn['reply'])->not->toContain('All done');
});

test('manual mode holds even a plain instruction', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([[loopAddAna()], [['text' => 'Queued.']]]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'add Ana Cruz to cybersecurity analyst', mode: 'manual');

    expect(Applicant::count())->toBe(0)
        ->and(collect($turn['actions'])->where('kind', 'confirm'))->toHaveCount(1);
});

test('auto mode runs a write on a turn that carried a document', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([[loopAddAna()], [loopMoveAna()], [['text' => 'Done.']]]);

    app(Assistant::class)->handle(auth()->user(), 'put this person in cybersecurity analyst as an offer', [], [loopPdfPart()], mode: 'auto');

    expect(JobApplication::with('pipelineStage')->first()?->pipelineStage->name)->toBe('Offer');
});

test('auto mode still holds a consequential action', function () {
    loopRecruiter();
    $posting = loopAnalystPosting();
    fakeAssistantModel([[modelCall('delete_job_posting', ['posting' => 'Cybersecurity Analyst'])], [['text' => 'Deleted.']]]);
    auth()->user()->roles()->first()->permissions()->attach(Permission::where('name', 'recruitment.delete')->first());
    auth()->user()->unsetRelation('roles');
    app()->forgetInstance(Assistant::class);

    $turn = app(Assistant::class)->handle(auth()->user()->fresh(), 'delete the cybersecurity analyst posting', mode: 'auto');

    expect(JobPosting::find($posting->id))->not->toBeNull()
        ->and(collect($turn['actions'])->where('kind', 'confirm'))->toHaveCount(1);
});

test('auto mode still holds a write proposed on a question', function () {
    loopRecruiter();
    loopAnalystPosting();
    fakeAssistantModel([[loopAddAna()], [['text' => 'Added.']]]);

    app(Assistant::class)->handle(auth()->user(), 'who applied to cybersecurity analyst?', mode: 'auto');

    expect(Applicant::count())->toBe(0);
});

test('a turn makes at most five changes', function () {
    actingAsUserWith(['recruitment.view', 'recruitment.create']);
    loopAnalystPosting();
    $calls = [];

    foreach (range(1, 6) as $i) {
        $calls[] = [modelCall('add_applicant', ['first_name' => 'Person', 'last_name' => "Number{$i}", 'source' => 'other'])];
    }

    fakeAssistantModel([...$calls, [['text' => 'Done.']]]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'add these six people to the pool');

    expect(Applicant::count())->toBe(5)
        ->and(collect($turn['steps'])->last()['label'])->toBe('Stopped');
});

test('the tokens a turn spent are counted', function () {
    actingAsUserWith(['recruitment.view']);
    fakeAssistantModel([
        ['parts' => [modelCall('find_job_postings', [])], 'usage' => ['promptTokenCount' => 3000, 'candidatesTokenCount' => 12, 'cachedContentTokenCount' => 1000, 'thoughtsTokenCount' => 50]],
        ['parts' => [['text' => 'None open.']], 'usage' => ['promptTokenCount' => 3200, 'candidatesTokenCount' => 30]],
    ]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'list the job postings');

    expect($turn['usage'])->toBe(['requests' => 2, 'prompt_tokens' => 6200, 'output_tokens' => 42, 'cached_tokens' => 1000, 'thinking_tokens' => 50]);
});

test('a blocked answer is met with a polite refusal, not an error', function () {
    actingAsUserWith(['recruitment.view']);
    fakeAssistantModel([['parts' => [], 'finish' => 'SAFETY']]);

    $turn = app(Assistant::class)->handle(auth()->user(), 'something unfortunate');

    expect($turn['reply'])->toContain("can't help with that");
});

test('earlier turns are replayed with the steps they took', function () {
    actingAsUserWith(['recruitment.view']);
    $model = fakeAssistantModel([[['text' => 'OK.']]]);

    app(Assistant::class)->handle(auth()->user(), 'and the next one?', [
        ['role' => 'user', 'text' => 'add Ana to the analyst posting'],
        ['role' => 'assistant', 'text' => 'Added Ana.', 'steps' => ['Added Ana Cruz to “Cybersecurity Analyst”']],
    ]);

    expect($model->sent[0]['contents'][1]['parts'][0]['text'])->toContain('[Steps: Added Ana Cruz to “Cybersecurity Analyst”]');
});

// ── Confirming a plan (ADR 0068 §3) ──────────────────────────────────────────

/** Propose a plan through the real endpoint, with a CV attached; return the card. */
function proposePlan(array $script): array
{
    RateLimiter::clear('assistant-min:'.auth()->id());
    RateLimiter::clear('assistant-day:'.auth()->id());
    fakeAssistantModel($script);

    $response = test()->post(route('assistant'), [
        'message' => 'put this person in cybersecurity analyst as an offer',
        'files' => [UploadedFile::fake()->create('Ana_Cruz_CV.pdf', 30, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertOk();

    return collect($response->json('message.actions'))->firstWhere('kind', 'confirm');
}

test('confirming a plan runs its steps in order, once', function () {
    Storage::fake('assistant');
    Storage::fake('public');
    loopRecruiter();
    loopAnalystPosting();
    $card = proposePlan([[loopAddAna()], [loopMoveAna()], [['text' => 'Queued.']]]);

    expect(Applicant::count())->toBe(0);

    $answer = $this->postJson(route('assistant.actions.confirm'), ['token' => $card['confirmation']['token']])->assertOk();

    expect(JobApplication::with('pipelineStage')->first()?->pipelineStage->name)->toBe('Offer')
        ->and(collect($answer->json('message.steps'))->pluck('status')->all())->toBe(['done', 'done'])
        ->and($answer->json('message.body'))->toContain('Ana Cruz');

    $this->postJson(route('assistant.actions.confirm'), ['token' => $card['confirmation']['token']])->assertStatus(410);
    expect(JobApplication::count())->toBe(1);
});

test('a plan stops at the first step that fails, and says what never ran', function () {
    Storage::fake('assistant');
    loopRecruiter();
    loopAnalystPosting();
    $card = proposePlan([
        [modelCall('move_application', ['applicant' => 'Zed Nobody', 'stage' => 'Offer'])],
        [loopAddAna()],
        [['text' => 'Queued.']],
    ]);

    $answer = $this->postJson(route('assistant.actions.confirm'), ['token' => $card['confirmation']['token']])->assertOk();

    expect(Applicant::count())->toBe(0)
        ->and($answer->json('message.body'))->toContain("I couldn't do step 1")
        ->and($answer->json('message.body'))->toContain('Not run: add application')
        ->and(collect($answer->json('message.steps'))->pluck('status')->all())->toBe(['error', 'error']);
});

test('a single call held before plans existed still confirms', function () {
    $user = actingAsSuperAdmin();
    $token = str_repeat('b', 48);
    Cache::put('assistant:pending:'.hash('sha256', $token), [
        'user_id' => $user->id,
        'organization_id' => testOrganization()->id,
        'conversation_id' => null,
        'tool' => 'count_employees',
        'args' => [],
        'summary' => 'Count employees',
    ], now()->addMinutes(5));

    $taken = app(PendingActions::class)->take($user, $token);

    expect($taken['steps'])->toBe([['tool' => 'count_employees', 'args' => [], 'title' => 'Count employees']]);
});

test('a plan that lapsed mid-turn refuses the next step rather than splitting the plan', function () {
    loopRecruiter();
    loopAnalystPosting();
    $model = fakeAssistantModel([[loopAddAna()], [loopMoveAna()], [['text' => 'Queued.']]]);

    // The held plan lapses (its cache entry is gone) before the second step
    // would join it — the model is slow, or the cache was cleared.
    $lapsing = new class($model) extends GeminiClient
    {
        private int $calls = 0;

        public function __construct(private readonly GeminiClient $inner)
        {
            parent::__construct(null, 'stub');
        }

        public function configured(): bool
        {
            return true;
        }

        public function generate(array $contents, array $functionDeclarations = [], ?string $systemInstruction = null): array
        {
            if (++$this->calls === 2) {
                Cache::flush();
            }

            return $this->inner->generate($contents, $functionDeclarations, $systemInstruction);
        }
    };
    app()->instance(GeminiClient::class, $lapsing);
    app()->forgetInstance(Assistant::class);

    $turn = app(Assistant::class)->handle(auth()->user(), 'put this person in cybersecurity analyst as an offer', [], [loopPdfPart()]);

    $cards = collect($turn['actions'])->where('kind', 'confirm');

    expect($cards)->toHaveCount(1)
        ->and($cards->first()['plan'])->toHaveCount(1)
        ->and(collect($turn['steps'])->last()['status'])->toBe('error')
        ->and(collect($turn['steps'])->last()['detail'])->toContain('expired');
});
