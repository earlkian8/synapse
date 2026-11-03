<?php

use App\Support\Recruitment\Skills\Esco\EscoConcept;
use App\Support\Recruitment\Skills\Esco\EscoLabels;

/**
 * Which of ESCO's answers names what HR typed (ADR 0100). ESCO's search is a
 * full-text search, so this is what keeps "MS Excel" from becoming a cooking
 * skill. DB-free.
 */
function escoLabelsConcept(string $label, array $alt = [], string $id = ''): EscoConcept
{
    return new EscoConcept(EscoConcept::URI_PREFIX.($id ?: md5($label)), $label, $alt);
}

test('a loose full-text hit is refused: "MS Excel" is not "pursue excellence"', function () {
    $answers = [
        escoLabelsConcept('pursue excellence in the creation of food products', ['seek excellence in the creation of food products']),
        escoLabelsConcept('demand excellence from performers'),
        escoLabelsConcept('manage quality', ['pursue excellence in workplace processes']),
    ];

    expect(EscoLabels::best('MS Excel', $answers))->toBeNull();
});

test('the same words in another order and form name the skill', function () {
    $answers = [
        escoLabelsConcept('operation of lifting gears'),
        escoLabelsConcept('operate forklift', ['use a forklift', 'drive forklift']),
    ];

    expect(EscoLabels::best('Forklift operation', $answers)?->label)->toBe('operate forklift');
});

test('an other label names the skill', function () {
    $answer = escoLabelsConcept('perform venepuncture procedures', ['conduct venepuncture procedures', 'phlebotomy']);

    expect(EscoLabels::best('Phlebotomy', [$answer]))->toBe($answer);
});

test('a light verb and a trailing generic noun are set aside', function () {
    $answers = [
        escoLabelsConcept('use venepuncture procedure equipment'),
        escoLabelsConcept('perform venepuncture procedures'),
    ];

    expect(EscoLabels::best('Venepuncture', $answers)?->label)->toBe('perform venepuncture procedures')
        ->and(EscoLabels::reduce('perform venepuncture procedures'))->toBe('venepuncture')
        ->and(EscoLabels::reduce('use'))->toBe('use');
});

test('a parenthesis is set aside, and qualifiers around what HR typed', function () {
    expect(EscoLabels::best('Python', [escoLabelsConcept('Python (computer programming)')])?->label)->toBe('Python (computer programming)')
        ->and(EscoLabels::best('Strong bookkeeping skills', [escoLabelsConcept('accounting', ['bookkeeping'])])?->label)->toBe('accounting');
});

test('a preferred label beats somebody else’s other label, whatever ESCO ranked first', function () {
    $answers = [
        escoLabelsConcept('accountant', ['bookkeeper', 'accounting technician']),
        escoLabelsConcept('bookkeeper', ['general bookkeeper']),
    ];

    expect(EscoLabels::best('Bookkeeper', $answers)?->label)->toBe('bookkeeper');
});

test('one shared word is not the same skill', function () {
    expect(EscoLabels::best('Vendor accreditation', [
        escoLabelsConcept('ensure legal accreditation for forwarding agent operations'),
        escoLabelsConcept('procurement of ICT network equipment', ['network equipment vendors']),
    ]))->toBeNull();
});

test('ESCO’s answer is read and cut to size, and only skills are taken', function () {
    $result = [
        'uri' => 'http://data.europa.eu/esco/skill/2fcd95a4-1f9a-41cf-95a9-4701bde476b4',
        'title' => 'operate welding equipment',
        'preferredLabel' => ['en' => 'operate welding equipment', 'de' => 'Schweißausrüstung bedienen'],
        'alternativeLabel' => ['en' => ['making welds', "weld\u{0007}ing", '  making   welds ', 'operate welding equipment', ...array_fill(0, 60, 'x')]],
        'description' => ['en' => ['literal' => 'Use welding equipment to melt and join together pieces of metal.', 'mimetype' => 'plain/text']],
        '_links' => [
            'hasSkillType' => [['uri' => 'http://data.europa.eu/esco/skill-type/skill', 'title' => 'skill']],
            'narrowerSkill' => [
                ['uri' => 'http://data.europa.eu/esco/skill/aaa', 'title' => 'tend laser beam welding machine'],
                ['uri' => 'https://example.com/not-esco', 'title' => 'something else'],
            ],
            'broaderSkill' => [['uri' => 'http://data.europa.eu/esco/skill/bbb', 'title' => 'follow safety precautions in work practices']],
        ],
    ];

    $concept = EscoConcept::fromApi($result, 'en');

    expect($concept->label)->toBe('operate welding equipment')
        ->and($concept->altLabels)->toBe(['making welds', 'weld ing', 'x'])
        ->and($concept->description)->toBe('Use welding equipment to melt and join together pieces of metal.')
        ->and($concept->skillType)->toBe('skill')
        ->and($concept->narrower)->toBe([['uri' => 'http://data.europa.eu/esco/skill/aaa', 'label' => 'tend laser beam welding machine']])
        ->and($concept->broader[0]['label'])->toBe('follow safety precautions in work practices')
        ->and(EscoConcept::fromApi(['uri' => 'http://data.europa.eu/esco/occupation/x', 'title' => 'welder'], 'en'))->toBeNull()
        ->and(EscoConcept::fromApi(['uri' => 'http://data.europa.eu/esco/skill/y', 'preferredLabel' => ['de' => 'nur Deutsch']], 'en'))->toBeNull();
});
