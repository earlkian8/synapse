<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Organization;
use App\Models\ProblemReport;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Files\Uploads;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| ADR 0096 — the portal's Problem reports: every organisation's, open ones
| first, followed up by any admin. Each change is in the admin log, and the
| reporter is told when it moves on or the reply changes.
*/

beforeEach(fn () => Storage::fake(Uploads::DISK));

/**
 * A report from somebody in a company of its own, made the way the ERP makes
 * one — through the dialog's endpoint — so it carries a real screenshot.
 */
function apReport(string $company, array $input = [], bool $screenshot = false): ProblemReport
{
    $organization = Organization::factory()->create(['name' => $company]);
    $tenancy = app(Tenancy::class);
    $tenancy->set($organization);

    $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => $company.' Reporter', 'middle_name' => null, 'suffix' => null]);

    test()->actingAs($user)->postJson('/help/reports', [
        'category' => 'not_working',
        'impact' => 'workaround',
        'subject' => "Export fails at {$company}",
        'description' => 'The export button shows an error after a few seconds.',
        'page' => '/system/data-export',
        ...$input,
        ...($screenshot ? ['screenshots' => [UploadedFile::fake()->image('error.png', 300, 200)]] : []),
    ])->assertCreated();

    $tenancy->forget();
    app('auth')->forgetGuards();

    return ProblemReport::query()->latest('id')->firstOrFail();
}

it('lists every organisation’s open reports by default, with their counts', function () {
    $alpha = apReport('Alpha', ['impact' => 'blocked']);
    $beta = apReport('Beta');
    $done = apReport('Gamma');
    $done->update(['status' => 'resolved', 'resolved_at' => now()]);

    $this->getJson('/api/admin/problem-reports', asPortalAdmin())
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('filters.status', 'open')
        ->assertJsonPath('stats.new', 2)
        ->assertJsonPath('stats.blocked', 1)
        ->assertJsonPath('stats.resolved_30d', 1)
        ->assertJsonPath('stats.total', 3)
        ->assertJsonPath('data.0.reference', $beta->reference)
        ->assertJsonPath('data.1.organization.name', 'Alpha')
        ->assertJsonPath('data.1.reporter.email', $alpha->user->email);

    $this->getJson('/api/admin/problem-reports?status=all&sort=impact&direction=desc', asPortalAdmin())
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.reference', $alpha->reference);

    $this->getJson('/api/admin/problem-reports?status=resolved', asPortalAdmin())
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reference', $done->reference);
});

it('searches by reference, summary, reporter and organisation, and filters', function () {
    $alpha = apReport('Alpha');
    apReport('Beta', ['category' => 'slow']);

    $this->getJson('/api/admin/problem-reports?search='.strtolower($alpha->reference), asPortalAdmin())
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/problem-reports?search=ana+beta', asPortalAdmin())
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.organization.name', 'Beta');
    $this->getJson('/api/admin/problem-reports?category=slow', asPortalAdmin())
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('filters.category', 'slow');
    $this->getJson('/api/admin/problem-reports?platform=ios&category=bogus', asPortalAdmin())
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('filters.category', 'all');
});

it('opens one report with what the device sent and its screenshots', function () {
    $report = apReport('Alpha', [], screenshot: true);

    $this->getJson("/api/admin/problem-reports/{$report->id}", asPortalAdmin(Admin::factory()->create()))
        ->assertOk()
        ->assertJsonPath('data.description', 'The export button shows an error after a few seconds.')
        ->assertJsonPath('data.page', '/system/data-export')
        ->assertJsonPath('data.platform_label', 'Web')
        ->assertJsonPath('data.attachments.0.width', 300)
        ->assertJsonMissingPath('data.attachments.0.path');
});

it('streams a screenshot to the admin’s token, and only under its own report', function () {
    $report = apReport('Alpha', [], screenshot: true);
    $other = apReport('Beta');
    $attachment = $report->attachments->sole();

    $this->get("/api/admin/problem-reports/{$report->id}/attachments/{$attachment->id}", asPortalAdmin())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->get("/api/admin/problem-reports/{$other->id}/attachments/{$attachment->id}", asPortalAdmin())->assertNotFound();

    app('auth')->forgetGuards();
    $this->get("/api/admin/problem-reports/{$report->id}/attachments/{$attachment->id}", ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

it('is closed to ERP accounts and to signed-out callers', function () {
    $report = apReport('Alpha');

    $this->getJson('/api/admin/problem-reports')->assertUnauthorized();
    $this->patchJson("/api/admin/problem-reports/{$report->id}", ['status' => 'closed'])->assertUnauthorized();

    $token = $report->user->createToken('mobile')->plainTextToken;
    app('auth')->forgetGuards();
    $this->getJson('/api/admin/problem-reports', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
});

it('resolves a report with a reply, logs it and tells the reporter', function () {
    Notification::fake();
    $report = apReport('Alpha');
    $admin = Admin::factory()->create(['name' => 'Rico Admin']);

    $this->patchJson("/api/admin/problem-reports/{$report->id}", [
        'status' => 'resolved',
        'reply' => 'Fixed — exports with archived employees work again.',
        'internal_note' => 'Null department on archived rows.',
    ], asPortalAdmin($admin))
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.handled_by', 'Rico Admin')
        ->assertJsonPath('data.internal_note', 'Null department on archived rows.');

    $report->refresh();
    expect($report->resolved_at)->not->toBeNull()
        ->and($report->replied_at)->not->toBeNull()
        ->and($report->handled_by)->toBe($admin->id);

    $log = AdminActivityLog::query()->where('event', 'problem_report_updated')->sole();
    expect($log->subject_label)->toBe($report->reference)
        ->and($log->description)->toContain('marked it Resolved')
        ->and($log->description)->toContain('replied to the reporter')
        ->and($log->properties['status'])->toBe(['from' => 'new', 'to' => 'resolved']);

    Notification::assertSentTo($report->user, SystemNotification::class, fn (SystemNotification $n) => $n->title === "{$report->reference} is resolved"
        && $n->body === 'Fixed — exports with archived employees work again.'
        && $n->url === '/help/reports?report='.$report->reference
        && $n->category === 'support');
});

it('tells the reporter nothing about an internal note, and records nothing for no change', function () {
    Notification::fake();
    $report = apReport('Alpha');

    $this->patchJson("/api/admin/problem-reports/{$report->id}", ['status' => 'new', 'internal_note' => 'Likely a duplicate.'], asPortalAdmin())
        ->assertOk();
    $this->patchJson("/api/admin/problem-reports/{$report->id}", ['status' => 'new', 'internal_note' => 'Likely a duplicate.'], asPortalAdmin())
        ->assertOk();

    expect(AdminActivityLog::query()->where('event', 'problem_report_updated')->count())->toBe(1);
    Notification::assertNothingSent();

    $this->patchJson("/api/admin/problem-reports/{$report->id}", ['status' => 'investigating'], asPortalAdmin())->assertOk();

    Notification::assertSentTo($report->user, SystemNotification::class, fn (SystemNotification $n) => $n->title === "We’re looking into {$report->reference}");
});

it('validates a follow-up', function () {
    $report = apReport('Alpha');

    $this->patchJson("/api/admin/problem-reports/{$report->id}", ['status' => 'fixed', 'reply' => str_repeat('a', 2001)], asPortalAdmin())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'reply']);
});

it('counts the reports waiting, for the sidebar and the overview', function () {
    apReport('Alpha');
    apReport('Beta')->update(['status' => 'investigating']);

    $this->getJson('/api/admin/problem-reports/counts', asPortalAdmin())
        ->assertOk()
        ->assertJson(['new' => 1, 'open' => 2]);

    $this->getJson('/api/admin/overview', asPortalAdmin())
        ->assertJsonPath('stats.open_reports', 2)
        ->assertJsonPath('stats.new_reports', 1)
        ->assertJsonCount(2, 'open_reports');
});
