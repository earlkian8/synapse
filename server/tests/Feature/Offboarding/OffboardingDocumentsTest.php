<?php

use App\Models\ActivityLog;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| An exit's papers (ADR 0077) — the clearance form and the Certificate of
| Employment as PDFs, on the web and through the app's short-lived link — and
| the app's own resignation and exit-interview endpoints (ADR 0076).
*/

/**
 * @return array{0: User, 1: Employee}
 */
function documentHolder(): array
{
    $role = makeRole('docs-'.Str::random(8), ['offboarding.resign']);
    $user = User::factory()->create(['is_active' => true]);
    $user->roles()->attach($role);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'employment_status' => 'active', 'date_hired' => '2021-03-01']);

    return [$user, $employee];
}

test('HR prints the clearance form at any point of the exit', function () {
    [, $employee] = documentHolder();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    ClearanceItem::create(['offboarding_case_id' => $case->id, 'item' => 'Return laptop', 'status' => 'pending', 'sort_order' => 1]);
    actingAsUserWith(['offboarding.view']);

    $response = $this->get(route('offboarding.documents.clearance', $case));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue()
        ->and($response->headers->get('Content-Disposition'))->toContain('clearance-form-');
});

test('the certificate needs the exit completed, is numbered once, and its first issue is audited', function () {
    [, $employee] = documentHolder();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance', 'last_working_day' => '2026-10-30']);
    actingAsUserWith(['offboarding.view']);

    $this->get(route('offboarding.documents.certificate', $case))->assertRedirect();
    assertToast('warning', 'once the exit is completed');

    $case->update(['status' => 'completed', 'completed_at' => now()]);

    $first = $this->get(route('offboarding.documents.certificate', $case));
    $first->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $number = $case->refresh()->certificate_no;

    $this->get(route('offboarding.documents.certificate', $case))->assertOk();

    expect($number)->toBe('COE-'.CarbonImmutable::parse(OrganizationClock::today())->year.'-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT))
        ->and($case->refresh()->certificate_no)->toBe($number)
        ->and(ActivityLog::query()->where('description', 'like', 'Issued a Certificate of Employment%')->count())->toBe(1);
});

test('the leaver downloads their own certificate on the web once their exit is completed', function () {
    [$user, $employee] = documentHolder();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    $this->actingAs($user);

    $this->get(route('offboarding.me.certificate'))->assertRedirect();
    assertToast('warning');

    $case->update(['status' => 'completed', 'completed_at' => now()]);

    $this->get(route('offboarding.me.certificate'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(ActivityLog::query()->where('description', 'like', '%at their request')->exists())->toBeTrue();
});

test('the app files and withdraws a resignation and reads the exit', function () {
    [$user, $employee] = documentHolder();
    Sanctum::actingAs($user);

    $this->getJson('/api/offboarding')->assertOk()->assertJsonPath('data.can_resign', true);

    $filed = $this->postJson('/api/offboarding/resignation', [
        'proposed_last_day' => CarbonImmutable::parse(OrganizationClock::today())->addDays(30)->toDateString(),
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    $this->postJson('/api/offboarding/resignation', ['proposed_last_day' => '2000-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('proposed_last_day');

    $this->patchJson('/api/offboarding/resignation/'.$filed->json('data.id').'/withdraw')
        ->assertOk()->assertJsonPath('data.status', 'withdrawn');

    expect(ResignationRequest::query()->sole()->status)->toBe('withdrawn');
});

test('the app answers the exit interview', function () {
    [$user, $employee] = documentHolder();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    ExitInterview::factory()->create(['offboarding_case_id' => $case->id, 'employee_id' => $employee->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/offboarding/exit-interview')->assertOk()->assertJsonPath('data.can_answer', true);

    $answers = ['reason' => 'pay', 'recommend' => 'no', 'return' => 'no'];
    foreach (array_keys(ExitInterviewQuestionnaire::RATINGS) as $key) {
        $answers["rating_{$key}"] = 3;
    }

    $this->postJson('/api/offboarding/exit-interview', ['answers' => $answers])
        ->assertOk()->assertJsonPath('data.status', 'submitted');
});

test('the app\'s certificate link works for a few minutes, for its holder, without a token', function () {
    [$user, $employee] = documentHolder();
    $case = OffboardingCase::factory()->create(['employee_id' => $employee->id, 'status' => 'clearance']);
    Sanctum::actingAs($user);

    $this->postJson('/api/offboarding/certificate-link')->assertUnprocessable();

    $case->update(['status' => 'completed', 'completed_at' => now()]);
    $url = $this->postJson('/api/offboarding/certificate-link')->assertOk()->json('data.url');

    app(Tenancy::class)->forget();
    auth()->forgetGuards();

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Tampered with, or minted for somebody else, it opens nothing.
    $this->get($url.'x')->assertForbidden();
    [$other] = documentHolder();
    $forged = URL::temporarySignedRoute('api.documents.certificate', now()->addMinutes(5), ['case' => $case->hashid, 'user' => $other->id]);
    app(Tenancy::class)->forget();
    $this->get($forged)->assertNotFound();
});

test('the app\'s offboarding needs offboarding.resign', function () {
    $role = makeRole('nobody-'.Str::random(6), ['attendance.clock']);
    $user = User::factory()->create();
    $user->roles()->attach($role);
    Sanctum::actingAs($user);

    $this->getJson('/api/offboarding')->assertForbidden();
});
