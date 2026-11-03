<?php

use App\Support\Recruitment\Skills\CandidateEvidence;
use App\Support\Recruitment\Skills\Esco\EscoConcept;
use App\Support\Recruitment\Skills\SkillCatalogue;
use App\Support\Recruitment\Skills\SkillMatcher;
use App\Support\Recruitment\Skills\SkillTaxonomy;
use App\Support\Recruitment\Skills\TaxonomySkill;

/**
 * Skills the catalogue doesn't know, read through ESCO (ADR 0100): laid onto
 * the catalogue where the two agree, proven by narrower skills, given a head
 * start by broader ones, and never read more loosely than the catalogue reads
 * its own. DB-free: the taxonomy here is a list.
 */
function escoMatchTaxonomy(array $concepts): SkillTaxonomy
{
    return new class($concepts) implements SkillTaxonomy
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private array $concepts) {}

        public function concept(string $text): ?TaxonomySkill
        {
            $this->asked[] = $text;
            $concept = $this->concepts[mb_strtolower($text)] ?? null;

            return $concept ? TaxonomySkill::fromEsco($concept, SkillCatalogue::shared()) : null;
        }

        public function preload(array $texts): void {}
    };
}

function escoMatchConcept(string $label, array $alt = [], array $narrower = [], array $broader = []): EscoConcept
{
    $link = fn (string $title): array => ['uri' => EscoConcept::URI_PREFIX.md5($title), 'label' => $title];

    return new EscoConcept(EscoConcept::URI_PREFIX.md5($label), $label, $alt, null, 'skill', array_map($link, $broader), array_map($link, $narrower));
}

/**
 * @param  array<string, EscoConcept>  $concepts
 */
function escoMatch(string $skill, string $resume, array $concepts): array
{
    return SkillMatcher::make(escoMatchTaxonomy($concepts))->assess($skill, new CandidateEvidence(['résumé' => $resume]));
}

/** Words that are already shown by their own words, before ESCO. */
function escoMatchWithout(string $skill, string $resume): array
{
    return SkillMatcher::make()->assess($skill, new CandidateEvidence(['résumé' => $resume]));
}

test('the skills this relies on are ones the catalogue doesn’t know', function (string $skill) {
    expect(SkillCatalogue::shared()->resolve($skill))->toBeNull();
})->with(['Venepuncture', 'Lifting equipment operation', 'Tower crane operation', 'Scaffolding', 'Python (computer programming)']);

test('a catalogue skill another label names proves it: Phlebotomy shows Venepuncture', function () {
    $concepts = ['venepuncture' => escoMatchConcept('perform venepuncture procedures', ['conduct venepuncture procedures', 'phlebotomy'])];

    $result = escoMatch('Venepuncture', 'Phlebotomist at a diagnostic clinic, 2019–2024', $concepts);

    expect($result['status'])->toBe('implied')
        ->and($result['via'])->toBe('Phlebotomy')
        ->and($result['credit'])->toBe(1.0)
        ->and($result['recognised'])->toBeTrue()
        ->and($result['taxonomy'])->toMatchArray(['source' => 'esco', 'label' => 'perform venepuncture procedures'])
        ->and(escoMatchWithout('Venepuncture', 'Phlebotomist at a diagnostic clinic, 2019–2024')['status'])->toBe('missing');
});

test('ESCO’s own labels count, in other words too', function () {
    $concepts = ['venepuncture' => escoMatchConcept('perform venepuncture procedures', ['draw blood samples', 'conduct venepuncture procedures'])];

    $result = escoMatch('Venepuncture', 'Drawing blood samples from forty patients a day', $concepts);

    expect($result['status'])->toBe('found')
        ->and($result['via'])->toBe('drawing blood samples')
        ->and($result['credit'])->toBe(1.0)
        ->and(escoMatchWithout('Venepuncture', 'Drawing blood samples from forty patients a day')['status'])->toBe('missing');
});

test('a narrower skill proves it, through the catalogue when the catalogue knows it', function () {
    $concepts = ['lifting equipment operation' => escoMatchConcept('operate lifting equipment', narrower: ['operate forklift', 'operate tower crane'])];

    $byCatalogue = escoMatch('Lifting equipment operation', 'Forklift operator, five years in a cold store', $concepts);
    $byEsco = escoMatch('Lifting equipment operation', 'Experienced in operating a tower crane', $concepts);

    expect($byCatalogue['status'])->toBe('implied')
        ->and($byCatalogue['via'])->toBe('Forklift operation')
        ->and($byEsco['status'])->toBe('implied')
        ->and($byEsco['via'])->toBe('operate tower crane');
});

test('a broader skill is only a head start', function () {
    $concepts = ['tower crane operation' => escoMatchConcept('operate tower crane', broader: ['operate crane'])];

    $result = escoMatch('Tower crane operation', 'Operated mobile cranes for a port operator', $concepts);

    expect($result['status'])->toBe('related')
        ->and($result['via'])->toBe('operate crane')
        ->and($result['credit'])->toBe(SkillMatcher::PARENT_CREDIT);
});

test('an ESCO skill that is a catalogue skill is matched as the catalogue matches it', function () {
    $concepts = ['python (computer programming)' => escoMatchConcept('Python (computer programming)', ['Py3K'])];

    $result = escoMatch('Python (computer programming)', 'Django and FastAPI services', $concepts);

    expect($result['status'])->toBe('implied')
        ->and($result['via'])->toBe('Django')
        ->and($result['taxonomy']['label'])->toBe('Python (computer programming)');
});

test('ESCO only ever adds: a skill shown by its own words stays shown', function () {
    // ESCO's labels say nothing this résumé uses, but HR's own words are in it.
    $concepts = ['scaffolding' => escoMatchConcept('erect scaffolding', ['assemble scaffolding'])];

    $result = escoMatch('Scaffolding', 'Inspected scaffolds before every handover', $concepts);

    expect($result['status'])->toBe('found')
        ->and($result['credit'])->toBe(1.0)
        ->and($result['taxonomy'])->not->toBeNull();
});

test('a catalogue skill is never sent to the taxonomy', function () {
    $taxonomy = escoMatchTaxonomy([]);

    SkillMatcher::make($taxonomy)->match(['JavaScript', 'Bookkeeping or Venepuncture'], ['Excel'], new CandidateEvidence(['résumé' => 'TypeScript']));

    expect($taxonomy->asked)->toBe(['Venepuncture']);
});

test('a catalogue word among ESCO’s labels keeps the catalogue’s care: "excel at" is not Excel', function () {
    $concepts = ['spreadsheet modelling' => escoMatchConcept('use spreadsheets software', ['Excel', 'spreadsheet modelling'])];

    expect(escoMatch('Spreadsheet modelling', 'I excel at customer care', $concepts)['status'])->toBe('missing')
        ->and(escoMatch('Spreadsheet modelling', 'Built forecasts in Excel', $concepts)['via'])->toBe('Excel');
});

test('a one-word label must be distinctive, and filler never counts', function () {
    $concepts = ['site induction' => escoMatchConcept('conduct site induction', ['use', 'skills', 'site', 'conduct inductions'])];

    expect(escoMatch('Site induction', 'Skills: welding. I use my site pass daily.', $concepts)['status'])->toBe('missing')
        ->and(escoMatch('Site induction', 'Conducted inductions for new hires', $concepts)['status'])->toBe('found');
});

test('without a taxonomy nothing changes', function () {
    $result = escoMatchWithout('Venepuncture', 'Phlebotomist');

    expect($result['status'])->toBe('missing')
        ->and($result['recognised'])->toBeFalse()
        ->and($result['taxonomy'])->toBeNull();
});
