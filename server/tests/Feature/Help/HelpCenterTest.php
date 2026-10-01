<?php

use App\Services\Assistant\AssistantAccess;
use App\Services\Assistant\Modules\SystemGuideModule;
use App\Support\Help\HelpCenter;
use App\Support\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Help Center (ADR 0062): SYNAPSE's user manual, always read for the
| signed-in person — an article about a screen they cannot open is never
| listed, searched, linked or served to them.
*/

/** The built-in Staff role's permissions: self-service only. */
function helpStaffPermissions(): array
{
    return ['attendance.clock', 'leave.request'];
}

/** Whether a GET to this app path reaches a real route. */
function helpRouteExists(string $path): bool
{
    try {
        return Route::getRoutes()->match(Request::create($path, 'GET')) !== null;
    } catch (Throwable) {
        return false;
    }
}

// ── The catalogue ────────────────────────────────────────────────────────────

test('every article has its words, in its category, and every file is in the catalogue', function () {
    $catalogued = collect(HelpCenter::ARTICLES)->keys()->map(fn (string $slug): string => HelpCenter::path($slug));
    $files = collect(File::allFiles(resource_path('help')))->map(fn ($file): string => $file->getPathname());

    expect($catalogued->reject(fn (string $path): bool => is_file($path) && trim((string) file_get_contents($path)) !== ''))->toBeEmpty()
        ->and($files->diff($catalogued)->values()->all())->toBe([])
        ->and(collect(HelpCenter::ARTICLES)->pluck('category')->diff(array_keys(HelpCenter::CATEGORIES)))->toBeEmpty()
        ->and(collect(HelpCenter::CATEGORIES)->keys()->diff(collect(HelpCenter::ARTICLES)->pluck('category')))->toBeEmpty();
});

test('every permission an article names exists, and related reading points at real articles', function () {
    $permissions = collect(HelpCenter::ARTICLES)->pluck('any')->flatten()->unique();
    $related = collect(HelpCenter::ARTICLES)->pluck('related')->flatten()->filter()->unique();

    expect($permissions->diff(PermissionRegistry::names())->values()->all())->toBe([])
        ->and($related->diff(array_keys(HelpCenter::ARTICLES))->values()->all())->toBe([]);
});

test('every link in the manual leads somewhere real', function () {
    $broken = collect(HelpCenter::ARTICLES)->keys()->flatMap(function (string $slug): array {
        preg_match_all('~\]\((/[^)\s#]*)(?:#[^)]*)?\)~', HelpCenter::body($slug), $links);

        return collect($links[1])
            ->reject(function (string $link): bool {
                if (preg_match('~^/help/([a-z0-9-]+)/([a-z0-9-]+)$~', $link, $m)) {
                    return (HelpCenter::ARTICLES[$m[2]]['category'] ?? null) === $m[1];
                }

                return helpRouteExists($link);
            })
            ->map(fn (string $link): string => "{$slug} → {$link}")
            ->all();
    });

    expect($broken->all())->toBe([]);
});

test('every screen an article explains is a real page', function () {
    $screens = collect(HelpCenter::ARTICLES)->pluck('screens')->flatten()->filter()
        ->map(fn (string $screen): string => str_ends_with($screen, '/*') ? substr($screen, 0, -2) : $screen);

    expect($screens->reject(fn (string $path): bool => helpRouteExists($path))->values()->all())->toBe([]);
});

// ── Reading it ───────────────────────────────────────────────────────────────

test('the home lists only the topics and articles the reader may open', function () {
    actingAsUserWith(helpStaffPermissions());

    $this->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/index')
            ->where('categories', fn ($categories) => collect($categories)->pluck('key')->contains('getting-started')
                && ! collect($categories)->pluck('key')->contains('talent-acquisition')
                && ! collect($categories)->pluck('key')->contains('administration'))
            ->where('featured', fn ($featured) => collect($featured)->pluck('slug')->contains('clocking-in-and-out')
                && ! collect($featured)->pluck('slug')->contains('job-postings')));
});

test('an HR Manager sees every topic', function () {
    actingAsSuperAdmin();

    $this->get(route('help.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories', count(HelpCenter::CATEGORIES)));
});

test('a topic lists its articles, and a topic with nothing for the reader is not found', function () {
    actingAsUserWith(['leave.view', 'leave.manage']);

    $this->get(route('help.category', 'workforce'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/category')
            ->where('category.key', 'workforce')
            ->where('category.articles', fn ($articles) => collect($articles)->pluck('slug')->all() === ['managing-leave']));

    $this->get(route('help.category', 'talent-acquisition'))->assertNotFound();
    $this->get(route('help.category', 'no-such-topic'))->assertNotFound();
});

test('an article is served whole, with its neighbours and related reading', function () {
    actingAsSuperAdmin();

    $this->get(route('help.article', ['workforce', 'managing-leave']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/article')
            ->where('article.slug', 'managing-leave')
            ->where('article.title', 'Managing leave')
            ->where('article.body', fn (string $body) => str_contains($body, '## The requests inbox'))
            ->where('article.reading_minutes', fn (int $minutes) => $minutes >= 1)
            ->where('article.previous.slug', 'clocking-in-and-out')
            ->where('article.next.slug', 'filing-your-own-leave')
            ->where('article.related', fn ($related) => collect($related)->pluck('slug')->all() === ['leave-types', 'filing-your-own-leave']));
});

test('an article the reader may not open answers exactly like one that does not exist', function () {
    actingAsUserWith(helpStaffPermissions());

    $this->get(route('help.article', ['administration', 'user-accounts']))->assertNotFound();
    $this->get(route('help.article', ['administration', 'no-such-article']))->assertNotFound();
    // In the wrong topic, even when the reader may open it.
    $this->get(route('help.article', ['workforce', 'signing-in']))->assertNotFound();
});

test("a link to an article the reader can't open keeps its words and loses its address", function () {
    actingAsUserWith(helpStaffPermissions());

    $page = HelpCenter::article(auth()->user(), 'getting-started', 'welcome-to-synapse');

    // The link to the Setup Guide's article is gone, its words stay, and a
    // link the reader may follow is untouched.
    expect($page['body'])
        ->not->toContain('/help/getting-started/setting-up-your-company')
        ->toContain('See Setting up your company.')
        ->toContain('(/help/getting-started/finding-your-way-around)')
        ->and(collect($page['related'])->pluck('slug'))->not->toContain('roles-and-permissions');
});

test('related reading and the pager skip what the reader may not open', function () {
    actingAsUserWith(helpStaffPermissions());

    $page = HelpCenter::article(auth()->user(), 'workforce', 'clocking-in-and-out');
    $visible = HelpCenter::articles(auth()->user())->keys();

    expect(collect($page['related'])->pluck('slug')->diff($visible))->toBeEmpty()
        ->and($visible)->toContain($page['previous']['slug'], $page['next']['slug']);
});

// ── Searching ────────────────────────────────────────────────────────────────

test('search finds the article that answers the question, best first', function () {
    actingAsSuperAdmin();

    $this->get(route('help.search', ['q' => 'approve leave']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/search')
            ->where('query', 'approve leave')
            ->where('partial', false)
            ->where('terms', ['approve', 'leave'])
            ->where('results.0.slug', 'managing-leave')
            ->where('results.0.snippet', fn (string $snippet) => $snippet !== ''));
});

test('search never returns an article the reader may not open', function () {
    $staff = actingAsUserWith(helpStaffPermissions());

    $found = HelpCenter::search($staff, 'job posting pipeline candidates');
    $everything = HelpCenter::search($staff, 'the');

    expect($found['results']->pluck('slug')->intersect(['job-postings', 'candidates-and-the-pipeline', 'recruitment-pipelines']))->toBeEmpty()
        ->and($everything['results'])->toBeEmpty();
});

test('when nothing matches every word, the closest articles are offered and say so', function () {
    actingAsSuperAdmin();

    $found = HelpCenter::search(auth()->user(), 'passkey zebra');

    expect($found['partial'])->toBeTrue()
        ->and($found['results']->pluck('slug'))->toContain('password-and-security');
});

test('an empty search shows the topics, and an overlong one is refused', function () {
    actingAsUserWith(helpStaffPermissions());

    $this->get(route('help.search'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('query', '')
            ->where('results', [])
            ->has('categories'));

    $this->get(route('help.search', ['q' => str_repeat('a', 121)]))->assertSessionHasErrors('q');
});

// ── Help for this page ───────────────────────────────────────────────────────

test('help for this page opens the article that explains it', function () {
    actingAsSuperAdmin();

    $this->get(route('help.for-page', ['path' => '/leave']))
        ->assertRedirect('/help/workforce/managing-leave');
    $this->get(route('help.for-page', ['path' => '/recruitment']))
        ->assertRedirect('/help/talent-acquisition/job-postings');
    // A posting's own page is the pipeline's article, not the postings list's.
    $this->get(route('help.for-page', ['path' => '/recruitment/abc123']))
        ->assertRedirect('/help/talent-acquisition/candidates-and-the-pipeline');
    $this->get(route('help.for-page', ['path' => '/setup/wizard/departments']))
        ->assertRedirect('/help/getting-started/setting-up-your-company');
    // Nothing explains it: the Help Center's home.
    $this->get(route('help.for-page', ['path' => '/nowhere']))
        ->assertRedirect(route('help.index'));
});

test("help for a page the reader may not open is the Help Center's home", function () {
    actingAsUserWith(helpStaffPermissions());

    $this->get(route('help.for-page', ['path' => '/system/users']))
        ->assertRedirect(route('help.index'));
    $this->get(route('help.for-page', ['path' => '/attendance/me']))
        ->assertRedirect('/help/workforce/clocking-in-and-out');
});

test('help for this page only takes an address inside the app', function () {
    actingAsSuperAdmin();

    $this->get(route('help.for-page', ['path' => 'https://example.com']))->assertSessionHasErrors('path');
    $this->get(route('help.for-page', ['path' => '//example.com/leave']))->assertSessionHasErrors('path');
});

// ── Access ───────────────────────────────────────────────────────────────────

test('the Help Center is for people who are signed in', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
    $this->get(route('help.article', ['getting-started', 'welcome-to-synapse']))->assertRedirect(route('login'));
});

// ── The assistant ────────────────────────────────────────────────────────────

test('the browser is told whether the assistant is offered, by the same rule as its articles', function () {
    $staff = actingAsUserWith(helpStaffPermissions());

    $this->get(route('help.index'))->assertInertia(fn (Assert $page) => $page->where('auth.assistant', false));

    expect(HelpCenter::articles($staff)->keys())->not->toContain('meet-the-assistant');

    $lead = actingAsUserWith(['leave.view']);

    $this->get(route('help.index'))->assertInertia(fn (Assert $page) => $page->where('auth.assistant', true));

    expect(AssistantAccess::offeredTo($lead))->toBeTrue()
        ->and(HelpCenter::articles($lead)->keys())->toContain('meet-the-assistant')
        ->and(collect(AssistantAccess::PERMISSIONS)->diff(PermissionRegistry::names()))->toBeEmpty();
});

test("the assistant's guide points at the Help Center article, read for the asker", function () {
    $hr = actingAsSuperAdmin();
    $staff = actingAsUserWith(helpStaffPermissions());

    $hrCards = collect(app(SystemGuideModule::class)->run($hr, 'find_help', ['question' => 'how do I approve leave requests?'])->cards);
    $staffCards = collect(app(SystemGuideModule::class)->run($staff, 'find_help', ['question' => 'how do I approve leave requests?'])->cards);

    expect($hrCards->firstWhere('badge', 'Help Center')['subtitle'] ?? null)->toBe('/help/workforce/managing-leave')
        ->and($staffCards->where('badge', 'Help Center')->pluck('subtitle'))->not->toContain('/help/workforce/managing-leave');
});
