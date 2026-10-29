<?php

use App\Support\Recruitment\Skills\CandidateEvidence;
use App\Support\Recruitment\Skills\SkillMatcher;

/**
 * The ATS's skill matching (ADR 0097), DB-free: a candidate who writes
 * "TypeScript" has JavaScript, "Java" is not in "JavaScript", and words that
 * are also skill names ("excel at", "Juan R. Cruz") are not read as skills.
 *
 * @param  array<string, string|null>|string  $sources
 * @param  list<string>  $names
 * @return array{skill: string, tier: string, status: string, via: string|null, source: string|null, credit: float, recognised: bool}
 */
function atsAssess(string $skill, array|string $sources, array $names = []): array
{
    $sources = is_string($sources) ? ['résumé' => $sources] : $sources;

    return SkillMatcher::make()->assess($skill, new CandidateEvidence($sources, $names));
}

test('a skill that proves another earns it in full: TypeScript shows JavaScript', function () {
    $result = atsAssess('JavaScript', 'Front-end developer. TypeScript, Angular, RxJS.');

    expect($result['status'])->toBe('implied')
        ->and($result['via'])->toBe('TypeScript')
        ->and($result['credit'])->toBe(1.0)
        ->and($result['source'])->toBe('résumé');
});

test('proof is followed through several steps: Next.js shows React and JavaScript', function () {
    expect(atsAssess('React', 'Built storefronts in Next.js')['status'])->toBe('implied')
        ->and(atsAssess('JavaScript', 'Built storefronts in Next.js')['credit'])->toBe(1.0)
        ->and(atsAssess('PHP', 'Laravel 10 and Livewire')['via'])->toBe('Laravel')
        ->and(atsAssess('SQL', 'Five years on Postgres')['via'])->toBe('PostgreSQL');
});

test('the other direction is only a head start: JavaScript is partly TypeScript', function () {
    $result = atsAssess('TypeScript', 'Plain JavaScript and jQuery for years');

    expect($result['status'])->toBe('related')
        ->and($result['via'])->toBe('JavaScript')
        ->and($result['credit'])->toBe(SkillMatcher::PARENT_CREDIT);
});

test('other names, spacing, punctuation and versions are the same skill', function (string $skill, string $text) {
    expect(atsAssess($skill, $text)['credit'])->toBe(1.0);
})->with([
    'JS' => ['JavaScript', 'Skills: JS, HTML'],
    'NodeJS' => ['Node.js', 'NodeJS and Express'],
    'node js' => ['Node.js', 'node js backend'],
    'Postgre SQL' => ['PostgreSQL', 'Postgre SQL tuning'],
    'C#' => ['C#', 'Desktop apps in C# and WinForms'],
    'C++' => ['C++', 'Embedded C++ firmware'],
    '.NET Core' => ['.NET', 'APIs on .NET Core 8'],
    'ASP.NET' => ['ASP.NET', 'ASP.NET MVC portals'],
    'PHP8' => ['PHP', 'Upgraded legacy code to PHP8'],
    'HTML5' => ['HTML', 'HTML5 and CSS3'],
    'k8s' => ['Kubernetes', 'Ran k8s clusters'],
    'MS Excel' => ['Excel', 'Proficient in MS Excel'],
    'ReactJS typed by HR' => ['ReactJS', 'React hooks and Redux'],
    'Type Script' => ['TypeScript', 'Type Script'],
]);

test('a skill is found only as whole words', function () {
    expect(atsAssess('Java', 'JavaScript and TypeScript developer')['credit'])->toBeLessThan(1.0)
        ->and(atsAssess('Java', 'JavaScript and TypeScript developer')['status'])->not->toBe('found')
        ->and(atsAssess('Excel', 'I excel at customer communication')['status'])->toBe('missing')
        ->and(atsAssess('Excel', 'Excellent attendance record')['status'])->toBe('missing');
});

test('names that are everyday words count only where they are plainly the skill', function () {
    // "Excel" with its capital is the program; "excel at" is not.
    expect(atsAssess('Excel', 'Reports in Excel and Word')['status'])->toBe('found')
        // One letter, or a word like "Go", counts as an item of a list.
        ->and(atsAssess('R', 'Languages: Python, R, SQL')['status'])->toBe('found')
        ->and(atsAssess('Go', 'Backend: Go, Rust')['status'])->toBe('found')
        ->and(atsAssess('C', "C, C++\nEmbedded")['status'])->toBe('found')
        // A middle initial and a phrase are not.
        ->and(atsAssess('R', 'Juan R. Cruz, Python developer')['status'])->not->toBe('found')
        ->and(atsAssess('Go', 'Willing to go the extra mile')['status'])->toBe('missing')
        ->and(atsAssess('SEM', '1st Sem 2019, Dean’s lister')['status'])->toBe('missing');
});

test('the candidate’s own name is never read as a skill', function () {
    expect(atsAssess('Ruby', ['résumé' => "Ruby\nSantos, Manila"], ['ruby', 'santos'])['status'])->toBe('missing');
});

test('a related skill earns its family’s partial credit', function () {
    $vue = atsAssess('Vue', 'React and Redux, three years');
    $mysql = atsAssess('MySQL', 'PostgreSQL DBA');

    expect($vue['status'])->toBe('related')
        ->and($vue['via'])->toBe('React')
        ->and($vue['credit'])->toBe(0.5)
        ->and($mysql['credit'])->toBe(0.6)
        ->and(atsAssess('MariaDB', 'MySQL replication')['credit'])->toBe(0.9);
});

test('a skill made of parts is shown by all of them, and partly by some', function () {
    $full = atsAssess('Full-stack', 'React front ends on a Laravel API');
    $partly = atsAssess('MERN stack', 'React and Node.js');

    expect($full['status'])->toBe('parts')
        ->and($full['credit'])->toBe(1.0)
        ->and($full['via'])->toContain('React')
        ->and(atsAssess('Microsoft Office', 'Word, Excel and PowerPoint')['status'])->toBe('parts')
        ->and($partly['status'])->toBe('related')
        ->and($partly['credit'])->toBe(round(SkillMatcher::PARTS_CREDIT * 2 / 4, 2));
});

test('a one-letter slip in a long or technical name is forgiven, an ordinary word is not', function () {
    $slip = atsAssess('JavaScript', 'Javscript and HTML');

    expect($slip['status'])->toBe('spelling')
        ->and($slip['via'])->toBe('javscript')
        ->and(atsAssess('Kubernetes', 'Kubernates on GKE')['status'])->toBe('found')
        ->and(atsAssess('Welding', 'Wedding coordinator')['status'])->not->toBe('spelling');
});

test('a known skill of several words is found in other words, close together', function () {
    expect(atsAssess('Project management', 'Managed several projects end to end')['status'])->toBe('found')
        ->and(atsAssess('Inventory management', 'managed the inventory of two stores')['status'])->toBe('found')
        ->and(atsAssess('Time management', 'managed the team at the time of the merger')['status'])->toBe('missing');
});

test('a skill the catalogue does not know is matched by its words in any form', function () {
    $result = atsAssess('Vendor accreditation', 'Accredited new vendors for the plant');

    expect($result['status'])->toBe('found')
        ->and($result['recognised'])->toBeFalse()
        ->and(atsAssess('Vendor accreditation', 'Handled vendors')['status'])->toBe('missing');
});

test('HR may accept alternatives with “or”, and qualifiers do not change the skill', function () {
    expect(atsAssess('React or Vue', 'Vue 3 and Pinia')['status'])->toBe('found')
        ->and(atsAssess('React or Vue', 'Vue 3 and Pinia')['credit'])->toBe(1.0)
        ->and(atsAssess('Advanced Excel skills', 'Pivot tables and VLOOKUP')['credit'])->toBe(1.0)
        ->and(atsAssess('Strong knowledge of payroll', 'Payroll processing for 300 staff')['credit'])->toBe(1.0);
});

test('the Philippine back office is understood too', function () {
    expect(atsAssess('BIR compliance', 'Prepared alphalist and BIR 2316')['credit'])->toBe(1.0)
        ->and(atsAssess('Taxation', 'Filed eBIRForms monthly')['status'])->toBe('implied')
        ->and(atsAssess('Customer service', 'Inbound calls, voice account, 2 years BPO')['status'])->toBe('implied')
        ->and(atsAssess('Licensed Professional Teacher', 'LET passer 2019')['credit'])->toBe(1.0)
        ->and(atsAssess('Payroll', 'SSS No. 34-1234567-8')['status'])->toBe('missing');
});

test('evidence is taken from the most trusted source first and says where', function () {
    $result = atsAssess('Docker', [
        'résumé' => 'Deployed with Docker Compose',
        'headline' => 'DevOps · Docker',
    ]);

    expect($result['source'])->toBe('résumé')
        ->and(atsAssess('Docker', ['résumé' => null, 'cover note' => 'I use Docker daily'])['source'])->toBe('cover note');
});

test('every must-have and nice-to-have is assessed, in order', function () {
    $results = SkillMatcher::make()->match(['JavaScript', 'Docker'], ['AWS'], new CandidateEvidence(['résumé' => 'TypeScript, EC2']));

    expect(array_column($results, 'tier'))->toBe(['must', 'must', 'nice'])
        ->and(array_column($results, 'status'))->toBe(['implied', 'missing', 'found']);
});
