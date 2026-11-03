<?php

use App\Jobs\LookUpSkillsInEsco;
use App\Models\Applicant;
use App\Models\EscoSkill;
use App\Models\EscoSkillTerm;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Organization;
use App\Support\Recruitment\Skills\Esco\EscoSkills;
use App\Support\Recruitment\Skills\NoTaxonomy;
use App\Support\Recruitment\Skills\SkillTaxonomy;
use App\Support\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| The ATS learns from ESCO about the skills its catalogue doesn't know
| (ADR 0100): a posting's unknown skills are looked up after it is saved and
| kept, the fit score reads what was kept, only skill names and titles are
| ever sent, a loose answer is refused, an outage keeps what was known, and
| the posting form's questions are allowed only to those who write postings.
| ESCO is faked throughout; phpunit.xml turns it off for every other test.
*/

beforeEach(function () {
    config(['services.esco.enabled' => true]);
    Sleep::fake();
    Http::preventStrayRequests();

    // One fake, answering from whatever escoFake() last set: a second
    // Http::fake() would sit behind the first and never answer.
    $GLOBALS['escoFake'] = ['answers' => [], 'status' => 200];
    Http::fake(['ec.europa.eu/esco/api/search*' => function (Request $request) {
        ['answers' => $answers, 'status' => $status] = $GLOBALS['escoFake'];

        if ($status !== 200) {
            return Http::response(['error' => 'down'], $status);
        }

        $results = $answers[mb_strtolower((string) ($request->data()['text'] ?? ''))] ?? [];

        return Http::response(['total' => count($results), '_embedded' => ['results' => $results]]);
    }]);
});

/** One ESCO skill as `/search?full=true` returns it. */
function escoFakeSkill(string $label, array $alt = [], array $narrower = [], array $broader = []): array
{
    $link = fn (string $title): array => ['uri' => 'http://data.europa.eu/esco/skill/'.md5($title), 'title' => $title];

    return [
        'className' => 'Skill',
        'uri' => 'http://data.europa.eu/esco/skill/'.md5($label),
        'title' => $label,
        'searchHit' => $label,
        'preferredLabel' => ['en' => $label, 'de' => 'Deutsch'],
        'alternativeLabel' => ['en' => $alt],
        'description' => ['en' => ['literal' => "About {$label}.", 'mimetype' => 'plain/text']],
        '_links' => [
            'hasSkillType' => [['uri' => 'http://data.europa.eu/esco/skill-type/skill', 'title' => 'skill']],
            'narrowerSkill' => array_map($link, $narrower),
            'broaderSkill' => array_map($link, $broader),
        ],
    ];
}

/** One ESCO occupation as `/search?full=true` returns it. */
function escoFakeOccupation(string $label, array $alt = [], array $essential = [], array $optional = []): array
{
    $link = fn (string $title): array => ['uri' => 'http://data.europa.eu/esco/skill/'.md5($title), 'title' => $title];

    return [
        'className' => 'Occupation',
        'uri' => 'http://data.europa.eu/esco/occupation/'.md5($label),
        'title' => $label,
        'searchHit' => $label,
        'preferredLabel' => ['en' => $label],
        'alternativeLabel' => ['en' => $alt],
        '_links' => ['hasEssentialSkill' => array_map($link, $essential), 'hasOptionalSkill' => array_map($link, $optional)],
    ];
}

/**
 * Answer ESCO's search from these results, keyed by the lower-cased text
 * asked about; anything else has no results.
 *
 * @param  array<string, list<array<string, mixed>>>  $answers
 */
function escoFake(array $answers, int $status = 200): void
{
    $GLOBALS['escoFake'] = ['answers' => $answers, 'status' => $status];
}

/** The text of every request sent to ESCO. */
function escoAsked(): array
{
    return Http::recorded()->map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))->values()->all();
}

function escoVenepuncture(): array
{
    return ['venepuncture' => [
        escoFakeSkill('use venepuncture procedure equipment'),
        escoFakeSkill('perform venepuncture procedures', ['conduct venepuncture procedures', 'phlebotomy']),
    ]];
}

test('a posting’s unknown skill is looked up after it is saved, and its candidates are read by it', function () {
    escoFake(escoVenepuncture());
    actingAsSuperAdmin();
    $pipeline = seedDefaultPipeline();

    $this->post(route('recruitment.store'), [
        'title' => 'Medical Technologist',
        'recruitment_pipeline_id' => $pipeline->id,
        'employment_type' => 'regular',
        'openings' => 1,
        'status' => 'draft',
        'skills' => ['Venepuncture', 'Phlebotomy'],
        'preferred_skills' => ['Excel or Venepuncture'],
    ])->assertSessionHasNoErrors();

    // Only the skill the catalogue doesn't know, and only once.
    expect(escoAsked())->toBe(['Venepuncture'])
        ->and(EscoSkillTerm::query()->where('term', 'venepuncture')->value('status'))->toBe(EscoSkillTerm::MATCHED)
        ->and(EscoSkill::query()->sole()->label)->toBe('perform venepuncture procedures');

    $posting = JobPosting::query()->where('title', 'Medical Technologist')->firstOrFail();
    $applicant = Applicant::factory()->create(['headline' => 'Phlebotomist, 6 years', 'notes' => null]);
    $application = JobApplication::factory()->create(['job_posting_id' => $posting->id, 'applicant_id' => $applicant->id, 'cover_note' => null]);

    $fit = $this->getJson(route('recruitment.applications.show', $application))->assertOk()->json('data.fit');
    $venepuncture = collect(collect($fit['breakdown'])->firstWhere('key', 'skills')['skills'])->firstWhere('skill', 'Venepuncture');

    expect($venepuncture)->toMatchArray(['status' => 'implied', 'via' => 'Phlebotomy', 'credit' => 1, 'recognised' => true])
        ->and($venepuncture['taxonomy'])->toMatchArray(['source' => 'esco', 'label' => 'perform venepuncture procedures'])
        // Scoring read the database; nothing more went to ESCO.
        ->and(escoAsked())->toBe(['Venepuncture']);
});

test('a loose answer is refused and remembered, so it isn’t asked again', function () {
    escoFake(['ms excellence' => [escoFakeSkill('pursue excellence in the creation of food products')]]);
    $esco = app(EscoSkills::class);

    expect($esco->learn(['MS Excellence']))->toBe(1)
        ->and(EscoSkillTerm::query()->where('term', 'ms excellence')->value('status'))->toBe(EscoSkillTerm::NONE)
        ->and(EscoSkill::query()->count())->toBe(0)
        ->and($esco->learn(['MS Excellence']))->toBe(0)
        ->and(escoAsked())->toBe(['MS Excellence']);
});

test('when ESCO is unreachable, what was known stands and the rest is asked again', function () {
    escoFake(escoVenepuncture());
    $esco = app(EscoSkills::class);
    $esco->learn(['Venepuncture']);

    $this->travel(EscoSkillTerm::FRESH_DAYS[EscoSkillTerm::MATCHED] + 1)->days();
    escoFake([], status: 503);

    expect($esco->lookUp('Venepuncture')?->label)->toBe('perform venepuncture procedures')
        ->and(EscoSkillTerm::query()->where('term', 'venepuncture')->value('status'))->toBe(EscoSkillTerm::MATCHED)
        ->and($esco->lookUp('Tower crane operation'))->toBeNull();

    $failed = EscoSkillTerm::query()->where('term', 'tower crane operation')->sole();

    expect($failed->status)->toBe(EscoSkillTerm::FAILED)
        ->and($failed->attempts)->toBe(1)
        ->and($failed->isFresh())->toBeFalse();

    // ESCO is back: the failed one is asked again and learned.
    escoFake(['tower crane operation' => [escoFakeSkill('operate tower crane', ['tower crane operation'])]]);

    expect($esco->learn(['Tower crane operation']))->toBe(1)
        ->and($failed->fresh()->status)->toBe(EscoSkillTerm::MATCHED);
});

test('a posting only sends what changed, and nothing when ESCO is off', function () {
    Bus::fake([LookUpSkillsInEsco::class]);
    testOrganization();

    $posting = JobPosting::factory()->create(['skills' => ['Venepuncture', 'JavaScript']]);
    Bus::assertDispatchedAfterResponse(LookUpSkillsInEsco::class, fn (LookUpSkillsInEsco $job): bool => $job->skills === ['Venepuncture']);

    $posting->update(['title' => 'Renamed']);
    Bus::assertDispatchedAfterResponseTimes(LookUpSkillsInEsco::class, 1);

    $posting->update(['preferred_skills' => ['JavaScript']]);
    Bus::assertDispatchedAfterResponseTimes(LookUpSkillsInEsco::class, 2);

    config(['services.esco.enabled' => false]);
    JobPosting::factory()->create(['skills' => ['Scaffolding']]);

    Bus::assertDispatchedAfterResponseTimes(LookUpSkillsInEsco::class, 2);
    expect(app(SkillTaxonomy::class))->toBeInstanceOf(NoTaxonomy::class)
        ->and(app(EscoSkills::class)->lookUp('Scaffolding'))->toBeNull();
});

test('recruitment:esco-lookup works off every workspace’s waiting skills, a limited number a run', function () {
    config(['services.esco.enabled' => false]);
    JobPosting::factory()->for(Organization::factory())->create(['skills' => ['Venepuncture']]);
    JobPosting::factory()->for(Organization::factory())->create(['skills' => ['React'], 'preferred_skills' => ['Tower crane operation']]);
    app(Tenancy::class)->forget();

    config(['services.esco.enabled' => true]);
    escoFake([...escoVenepuncture(), 'tower crane operation' => [escoFakeSkill('operate tower crane')]]);

    $this->artisan('recruitment:esco-lookup', ['--limit' => 1])->expectsOutputToContain('Looked up 1 skill(s)')->assertSuccessful();
    $this->artisan('recruitment:esco-lookup')->expectsOutputToContain('Looked up 1 skill(s)')->assertSuccessful();
    $this->artisan('recruitment:esco-lookup')->expectsOutputToContain('Looked up 0 skill(s)')->assertSuccessful();

    expect(EscoSkillTerm::query()->where('status', EscoSkillTerm::MATCHED)->count())->toBe(2)
        ->and(escoAsked())->not->toContain('React');

    $this->artisan('recruitment:esco-lookup', ['--again' => true])->expectsOutputToContain('Looked up 2 skill(s)')->assertSuccessful();
});

test('the posting form learns how a skill will be read', function () {
    escoFake(escoVenepuncture());
    actingAsUserWith(['recruitment.view', 'recruitment.create']);

    $this->getJson(route('recruitment.skills.lookup', ['q' => 'Venepuncture']))
        ->assertOk()
        ->assertJsonPath('source', 'esco')
        ->assertJsonPath('esco.label', 'perform venepuncture procedures')
        ->assertJsonPath('esco.proven_by', ['Phlebotomy'])
        ->assertJsonPath('esco.also', ['conduct venepuncture procedures', 'phlebotomy']);

    // A catalogue skill is the catalogue's, and is never sent.
    $this->getJson(route('recruitment.skills.lookup', ['q' => 'Advanced Excel skills']))
        ->assertOk()
        ->assertJsonPath('source', 'catalogue')
        ->assertJsonPath('esco', null);

    expect(escoAsked())->toBe(['Venepuncture']);
});

test('the posting form is offered ESCO skills that start with what is typed', function () {
    escoFake(['forkl' => [
        ['uri' => 'http://data.europa.eu/esco/skill/1', 'title' => 'operate forklift', 'searchHit' => 'operate forklift'],
        ['uri' => 'http://data.europa.eu/esco/skill/2', 'title' => 'drive agricultural machines', 'searchHit' => 'Drive tractors, forklifts and other vehicles to transport goods.'],
        ['uri' => 'http://data.europa.eu/esco/skill/3', 'title' => 'conduct forklift inspections', 'searchHit' => 'conduct forklift inspections'],
    ]]);
    actingAsUserWith(['recruitment.view', 'recruitment.update']);

    $this->getJson(route('recruitment.skills.suggest', ['q' => 'forkl']))
        ->assertOk()
        ->assertJsonPath('suggestions.0', ['label' => 'Operate forklift', 'also' => null, 'catalogue' => 'Forklift operation'])
        ->assertJsonPath('suggestions.1.label', 'Conduct forklift inspections')
        ->assertJsonCount(2, 'suggestions');

    // Kept for a day: asked once.
    $this->getJson(route('recruitment.skills.suggest', ['q' => 'Forkl']))->assertOk()->assertJsonCount(2, 'suggestions');

    expect(escoAsked())->toBe(['forkl']);
});

test('the posting form is offered a role’s essential and optional skills', function () {
    escoFake(['call center agent' => [
        escoFakeOccupation('call centre analyst', ['call center agent']),
        escoFakeOccupation('call centre agent', ['call centre operator'], ['maintain customer service', 'handle customer complaints', 'customer service'], ['cold calling']),
    ]]);
    actingAsUserWith(['recruitment.create']);

    $role = $this->getJson(route('recruitment.skills.role', ['q' => 'Senior Call Center Agent']))->assertOk()->json('role');

    // ESCO writes British English; the American title is its "call centre agent".
    expect($role['occupation'])->toMatchArray(['label' => 'Call centre agent', 'exact' => true])
        // "handle customer complaints" is the catalogue's Customer service, listed once.
        ->and($role['essential'])->toBe([
            ['name' => 'Maintain customer service', 'catalogue' => false],
            ['name' => 'Customer service', 'catalogue' => true],
        ])
        ->and($role['optional'])->toBe([['name' => 'Cold calling', 'catalogue' => true]]);
});

test('only those who write postings may ask, and every question is checked', function () {
    escoFake([]);
    actingAsUserWith(['recruitment.view']);

    $this->getJson(route('recruitment.skills.lookup', ['q' => 'Venepuncture']))->assertForbidden();
    $this->getJson(route('recruitment.skills.role', ['q' => 'Bookkeeper']))->assertForbidden();

    actingAsUserWith(['recruitment.create']);

    $this->getJson(route('recruitment.skills.lookup'))->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson(route('recruitment.skills.suggest', ['q' => str_repeat('a', 61)]))->assertUnprocessable();
    $this->getJson(route('recruitment.skills.role', ['q' => str_repeat('a', 256)]))->assertUnprocessable();

    expect(escoAsked())->toBe([]);
});

test('the posting form can’t lean on ESCO through us', function () {
    escoFake([]);
    actingAsUserWith(['recruitment.create']);

    foreach (range(1, 40) as $i) {
        $this->getJson(route('recruitment.skills.suggest', ['q' => "skill {$i}"]))->assertOk();
    }

    $this->getJson(route('recruitment.skills.suggest', ['q' => 'one more']))->assertTooManyRequests();
});
