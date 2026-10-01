<?php

use App\Support\Help\HelpCenter;
use App\Support\Legal\LegalDocuments;
use App\Support\SystemGuide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Privacy Policy and the Terms of Service (ADR 0063): public, written with
| this deployment's operator details, and reachable from wherever somebody is
| asked to agree to them.
*/

test('both documents are public, and read whole', function (string $key, string $title) {
    $this->get("/{$key}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('legal/show')
            ->where('document.key', $key)
            ->where('document.title', $title)
            ->where('document.effective', LegalDocuments::DOCUMENTS[$key]['effective'])
            ->where('document.body', fn (string $body) => str_contains($body, '## 1. '))
            ->has('document.highlights')
            ->where('document.others.0.href', $key === 'privacy' ? '/terms' : '/privacy'));
})->with([
    ['privacy', 'Privacy Policy'],
    ['terms', 'Terms of Service'],
]);

test('the operator details are written in, and no placeholder is left', function () {
    config([
        'legal.operator' => 'Acme HR Services, Inc.',
        'legal.contact_email' => 'legal@acme.test',
        'legal.privacy_email' => 'dpo@acme.test',
        'legal.address' => "12 Ayala Avenue,\n Makati City",
    ]);

    $privacy = LegalDocuments::find('privacy');
    $terms = LegalDocuments::find('terms');

    expect($privacy['body'])->toContain('**Acme HR Services, Inc.**')
        ->toContain('dpo@acme.test')
        ->toContain('12 Ayala Avenue, Makati City')
        ->not->toContain('{{')
        ->and($terms['body'])->toContain('legal@acme.test')
        ->not->toContain('{{')
        ->and($privacy['operator'])->toBe('Acme HR Services, Inc.');
});

test('without an address, the documents say how to get one', function () {
    config(['legal.address' => null]);

    expect(LegalDocuments::body('privacy'))->toContain('**Address:** available on request, by email');
});

test('the policy says truthfully whether the AI plan lets Google learn from what is sent', function () {
    config(['legal.ai_paid_plan' => false]);
    expect(LegalDocuments::body('privacy'))->toContain('may use what is sent to improve its products');

    config(['legal.ai_paid_plan' => true]);
    expect(LegalDocuments::body('privacy'))->toContain('Google does not use it to improve its products');
});

test('every link in the documents leads somewhere real', function () {
    $broken = collect(array_keys(LegalDocuments::DOCUMENTS))->flatMap(function (string $key): array {
        $body = LegalDocuments::body($key);
        preg_match_all('~\]\((/[^)\s#]*)(?:#[^)]*)?\)~', $body, $pages);
        preg_match_all('~\]\(#([a-z0-9-]+)\)~', $body, $anchors);

        // Anchors are made the way the page makes them: from each heading's words.
        $headings = collect(preg_split('/\R/', $body))
            ->filter(fn (string $line): bool => (bool) preg_match('/^#{2,3}\s/', $line))
            ->map(fn (string $line): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/^#+\s*/', '', $line))), '-'));

        return [
            ...collect($pages[1])->reject(function (string $path): bool {
                try {
                    return Route::getRoutes()->match(Request::create($path, 'GET')) !== null;
                } catch (Throwable) {
                    return false;
                }
            })->map(fn (string $path): string => "{$key} → {$path}")->all(),
            ...collect($anchors[1])->reject(fn (string $id): bool => $headings->contains($id))
                ->map(fn (string $id): string => "{$key} → #{$id}")->all(),
        ];
    });

    expect($broken->all())->toBe([]);
});

test('a signed-in owner part-way through company setup can still read them', function () {
    actingAsUserWith(['setup.company.manage']);
    testOrganization()->forceFill(['setup_completed_at' => null])->save();

    $this->get('/dashboard')->assertRedirect();
    $this->get('/privacy')->assertOk();
    $this->get('/terms')->assertOk();
});

test('the assistant and the Help Center know where they are', function () {
    $staff = actingAsUserWith(['attendance.clock', 'leave.request']);

    expect(SystemGuide::search($staff, 'where is the privacy policy?')->first()['path'] ?? null)->toBe('/privacy')
        ->and(HelpCenter::articles($staff)->keys())->toContain('privacy-and-your-data');
});
