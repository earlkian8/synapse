<?php

use App\Support\Recruitment\Skills\Skill;
use App\Support\Recruitment\Skills\SkillCatalogue;
use App\Support\Recruitment\Skills\Words;

/**
 * The skill catalogue's data stays sound (ADR 0097): every spelling belongs to
 * one skill, every reference names a real skill, and nothing proves itself.
 */
function atsCatalogueData(): array
{
    return require dirname(__DIR__, 3).'/app/Support/Recruitment/Skills/catalogue.php';
}

test('every spelling belongs to exactly one skill', function () {
    $owners = [];

    foreach (atsCatalogueData()['skills'] as $key => $entry) {
        $skill = Skill::fromEntry($key, $entry);
        $spellings = [
            ...array_column($skill->spellings, 'joined'),
            ...array_map(fn (string $word): string => implode('', Words::tokens($word)), [...$skill->cased, ...$skill->listed]),
        ];

        foreach (array_unique($spellings) as $joined) {
            $owners[$joined][] = $key;
        }
    }

    $shared = array_filter($owners, fn (array $keys): bool => count($keys) > 1);

    expect($shared)->toBe([]);
});

test('the catalogue builds: references resolve and no skill proves itself', function () {
    $catalogue = new SkillCatalogue(atsCatalogueData());

    expect(count($catalogue->all()))->toBeGreaterThan(300);
});

test('a loop in what proves what is refused', function () {
    new SkillCatalogue(['skills' => [
        'a' => ['name' => 'Alpha', 'implies' => ['b']],
        'b' => ['name' => 'Beta', 'implies' => ['a']],
    ]]);
})->throws(InvalidArgumentException::class);

test('an unknown reference is refused', function () {
    new SkillCatalogue(['skills' => ['a' => ['name' => 'Alpha', 'implies' => ['nope']]]]);
})->throws(InvalidArgumentException::class);

test('what HR types resolves to the skill it means', function (string $typed, string $name) {
    expect(SkillCatalogue::shared()->resolve($typed)?->name)->toBe($name);
})->with([
    ['ReactJS', 'React'],
    ['react.js', 'React'],
    ['JS', 'JavaScript'],
    ['Golang', 'Go'],
    ['Go', 'Go'],
    ['C#', 'C#'],
    ['MS Office', 'Microsoft Office'],
    ['Advanced Excel skills', 'Excel'],
    ['Experience with Postgres', 'PostgreSQL'],
    ['React development', 'React'],
    ['Pivot table', 'Excel'],
    ['BIR compliance', 'BIR compliance'],
]);

test('a skill the catalogue does not know resolves to nothing', function () {
    expect(SkillCatalogue::shared()->resolve('Vendor accreditation'))->toBeNull()
        ->and(SkillCatalogue::shared()->resolve('   '))->toBeNull();
});

test('provers are nearest first, and broad areas lend no partial credit', function () {
    $catalogue = SkillCatalogue::shared();
    $provers = array_map(fn (Skill $skill): string => $skill->name, $catalogue->proversOf($catalogue->get('javascript')));

    expect(array_search('TypeScript', $provers, true))->toBeLessThan(array_search('Next.js', $provers, true))
        ->and(array_map(fn (Skill $skill): string => $skill->key, $catalogue->parentsOf($catalogue->get('react'))))->toBe(['javascript']);
});

test('the posting form is told what else counts for each skill', function () {
    $javascript = collect(SkillCatalogue::shared()->describe()['skills'])->firstWhere('name', 'JavaScript');

    expect($javascript['aliases'])->toContain('js')
        ->and($javascript['proven_by'])->toContain('TypeScript')
        ->and($javascript['proven_by_count'])->toBeGreaterThan(count($javascript['proven_by']) - 1);
});

test('words fold the way people write skills', function () {
    expect(Words::fold('C++/C#, .NET & Node.js'))->toBe('cplusplus csharp dotnet and node js')
        ->and(Words::fold('juan.react@gmail.com knows Vue'))->toBe('knows vue')
        ->and(Words::stem('managing'))->toBe(Words::stem('management'))
        ->and(Words::stem('engineering'))->toBe(Words::stem('engineer'))
        ->and(Words::stem('process'))->toBe('process');
});
