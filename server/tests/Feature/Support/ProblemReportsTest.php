<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ProblemReport;
use App\Models\User;
use App\Support\Files\Uploads;
use App\Support\ProblemReports\ProblemReportTriage;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| ADR 0096 — reporting a problem to the SYNAPSE team, from the web's dialog and
| the app's form: one filing, screenshots drawn again on the private disk, and
| one's own reports with the team's reply — never the admins' note, never
| anybody else's.
*/

beforeEach(fn () => Storage::fake(Uploads::DISK));

/** What the web's dialog sends, less anything a test changes. */
function prWebReport(array $overrides = []): array
{
    return [
        'category' => 'not_working',
        'impact' => 'blocked',
        'subject' => 'Save does nothing on my leave request',
        'description' => 'I press Save on a new leave request and nothing happens. No message either.',
        'page' => '/leave?search=Ana+Reyes',
        'context' => ['viewport' => '1440×900', 'timezone' => 'Asia/Manila', 'language' => 'en-PH', 'theme' => 'dark'],
        ...$overrides,
    ];
}

/** A report filed for a user without going through HTTP. */
function prFiled(User $user, array $attributes = []): ProblemReport
{
    return ProblemReport::create([
        'reference' => 'PR-'.strtoupper(substr(str_shuffle('23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 6)),
        'user_id' => $user->id,
        'organization_id' => app(Tenancy::class)->id(),
        'category' => 'looks_wrong',
        'impact' => 'minor',
        'subject' => 'The calendar overlaps the sidebar',
        'description' => 'On a small laptop the calendar runs under the sidebar.',
        'platform' => 'web',
        'status' => 'new',
        ...$attributes,
    ]);
}

it('files a report from the web with screenshots, the page path and the browser', function () {
    $user = actingAsUserWith([]);

    $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0')
        ->postJson('/help/reports', prWebReport([
            'platform' => 'ios',
            'screenshots' => [
                UploadedFile::fake()->image('Screenshot 2026-10-21.png', 1200, 800),
                UploadedFile::fake()->image('photo.jpg', 900, 1600),
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.status_label', 'Received')
        ->assertJsonCount(2, 'data.attachments');

    $report = ProblemReport::sole();

    expect($report->reference)->toMatch('/^PR-[2-9A-HJKMNP-Z]{6}$/')
        ->and($response->json('data.reference'))->toBe($report->reference)
        ->and($report->user_id)->toBe($user->id)
        ->and($report->organization_id)->toBe(testOrganization()->id)
        // The web is the web, whatever it claims; the query string is dropped.
        ->and($report->platform)->toBe('web')
        ->and($report->page)->toBe('/leave')
        ->and($report->device)->toBe('Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0')
        ->and($report->context)->toMatchArray(['timezone' => 'Asia/Manila', 'theme' => 'dark']);

    [$png, $jpeg] = $report->attachments->all();

    expect($png->path)->toStartWith('problem-reports/')->toEndWith('.png')
        ->and($png->name)->toBe('Screenshot 2026-10-21.png')
        ->and([$png->width, $png->height])->toBe([1200, 800])
        ->and($jpeg->path)->toEndWith('.jpg')
        ->and($jpeg->mime)->toBe('image/jpeg');

    Storage::disk(Uploads::DISK)->assertExists([$png->path, $jpeg->path]);
});

it('draws every screenshot again, scaling a huge one down', function () {
    actingAsUserWith([]);

    $this->postJson('/help/reports', prWebReport([
        'screenshots' => [UploadedFile::fake()->image('wide.png', 5120, 1440)],
    ]))->assertCreated();

    $attachment = ProblemReport::sole()->attachments->sole();
    $size = getimagesizefromstring(Storage::disk(Uploads::DISK)->get($attachment->path));

    expect([$attachment->width, $attachment->height])->toBe([2560, 720])
        ->and([$size[0], $size[1]])->toBe([2560, 720]);
});

it('refuses a file that only claims to be an image, and more than three', function () {
    actingAsUserWith([]);

    $this->postJson('/help/reports', prWebReport([
        'screenshots' => [UploadedFile::fake()->createWithContent('shot.png', '<script>alert(1)</script>')],
    ]))->assertUnprocessable()->assertJsonValidationErrors('screenshots.0');

    $this->postJson('/help/reports', prWebReport([
        'screenshots' => array_map(fn (int $i) => UploadedFile::fake()->image("s{$i}.png", 10, 10), range(1, 4)),
    ]))->assertUnprocessable()->assertJsonValidationErrors('screenshots');

    expect(ProblemReport::count())->toBe(0);
    expect(Storage::disk(Uploads::DISK)->allFiles())->toBe([]);
});

it('answers a bad report with JSON the dialog can show', function () {
    actingAsUserWith([]);

    $this->post('/help/reports', ['category' => 'nonsense'], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'impact', 'subject', 'description'])
        ->assertJsonPath('errors.subject.0', 'Give the problem a short summary.');
});

it('logs that a report was sent in the workspace, but not what it says', function () {
    actingAsUserWith([]);

    $this->postJson('/help/reports', prWebReport())->assertCreated();

    $log = ActivityLog::query()->where('log_name', 'support')->sole();

    expect($log->description)->toContain(ProblemReport::sole()->reference)
        ->and($log->description)->not->toContain('leave request');
});

it('limits how many reports one person sends', function () {
    actingAsUserWith([]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/help/reports', prWebReport())->assertCreated();
    }

    $this->postJson('/help/reports', prWebReport())->assertTooManyRequests();
});

it('shows one their own reports with the team’s reply, never the internal note', function () {
    $user = actingAsUserWith([]);
    $mine = prFiled($user, [
        'status' => 'resolved',
        'reply' => 'Fixed in today’s release. Thanks!',
        'internal_note' => 'Was the z-index on the calendar popover.',
    ]);
    prFiled(User::factory()->create(), ['subject' => 'Somebody else’s report']);

    $this->get('/help/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/reports')
            ->has('reports', 1)
            ->where('reports.0.reference', $mine->reference)
            ->where('reports.0.status_label', 'Resolved')
            ->where('reports.0.reply', 'Fixed in today’s release. Thanks!')
            ->missing('reports.0.internal_note')
            ->missing('reports.0.handled_by'));
});

it('opens a screenshot for its reporter alone', function () {
    $user = actingAsUserWith([]);
    $this->postJson('/help/reports', prWebReport([
        'screenshots' => [UploadedFile::fake()->image('shot.png', 40, 40)],
    ]))->assertCreated();
    $path = ProblemReport::sole()->attachments->sole()->path;

    app(Tenancy::class)->forget();
    $this->actingAs($user)->get('/files/'.$path)->assertOk()->assertHeader('Content-Type', 'image/png');

    // A colleague in the same workspace has no business with it.
    $colleague = User::factory()->create();
    app(Tenancy::class)->forget();
    $this->actingAs($colleague)->get('/files/'.$path)->assertNotFound();
});

it('files, lists and opens reports from the app', function () {
    $user = actingAsUserWith([]);
    Sanctum::actingAs($user);

    $reference = $this->postJson('/api/problem-reports', [
        'category' => 'slow',
        'impact' => 'workaround',
        'subject' => 'Clock tab spins for ages',
        'description' => 'The Clock tab takes about a minute to load on mobile data.',
        'platform' => 'android',
        'page' => 'Clock',
        'app_version' => '1.0.0',
        'device' => 'Android 15',
        'screenshots' => [UploadedFile::fake()->image('clock.jpg', 1080, 2400)],
    ])
        ->assertCreated()
        ->assertJsonPath('data.platform_label', 'Android app')
        ->assertJsonPath('data.page', 'Clock')
        ->json('data.reference');

    $this->getJson('/api/problem-reports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $reference)
        ->assertJsonPath('data.0.attachments.0.url', fn (string $url) => str_contains($url, '/api/files/problem-reports/'));

    // Typed into an address, the case is forgiven.
    $this->getJson('/api/problem-reports/'.strtolower($reference))->assertOk()->assertJsonPath('data.subject', 'Clock tab spins for ages');
});

it('refuses the app a platform it isn’t, and another person’s report', function () {
    $user = actingAsUserWith([]);
    Sanctum::actingAs($user);

    $this->postJson('/api/problem-reports', [...prWebReport(), 'platform' => 'web'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');

    $theirs = prFiled(User::factory()->create());

    $this->getJson('/api/problem-reports/'.$theirs->reference)->assertNotFound();
    $this->getJson('/api/problem-reports/not-a-reference')->assertNotFound();
});

it('shows the reporter the reply once an admin answers', function () {
    $user = actingAsUserWith([]);
    Sanctum::actingAs($user);
    $report = prFiled($user);

    app(ProblemReportTriage::class)->update($report, [
        'status' => 'investigating',
        'reply' => 'Thanks — we can see it on 13-inch screens too.',
        'internal_note' => 'Check the sidebar width breakpoint.',
    ], Admin::factory()->create());

    $this->getJson('/api/problem-reports/'.$report->reference)
        ->assertOk()
        ->assertJsonPath('data.status_label', 'Being looked into')
        ->assertJsonPath('data.reply', 'Thanks — we can see it on 13-inch screens too.')
        ->assertJsonMissingPath('data.internal_note');
});
