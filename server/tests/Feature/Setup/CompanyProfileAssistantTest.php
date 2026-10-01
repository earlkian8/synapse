<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\CompanyProfileModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;

/*
| The company-profile capability of the assistant: the company's names,
| contact details and time zone, changed by the Company Profile screen's own
| rules — and never its statutory numbers or join code. Gemini is never called.
*/

function companyAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(CompanyProfileModule::class)->run($user, $tool, $args);
}

function companyAgentTools(User $user): array
{
    return array_column(app(CompanyProfileModule::class)->tools($user), 'name');
}

// ── Permissions ──────────────────────────────────────────────────────────────

test('a viewer reads; a manager edits; the time zone waits for a confirm', function () {
    expect(companyAgentTools(actingAsUserWith(['setup.company.view'])))->toBe(['get_company_profile'])
        ->and(companyAgentTools(actingAsUserWith(['setup.company.view', 'setup.company.manage'])))
        ->toBe(['get_company_profile', 'update_company_profile', 'set_company_timezone']);

    $module = app(CompanyProfileModule::class);

    expect($module->requiresConfirmation('set_company_timezone'))->toBeTrue()
        ->and($module->requiresConfirmation('update_company_profile'))->toBeFalse()
        ->and($module->isReadOnly('get_company_profile'))->toBeTrue();
});

test('a write is refused at run time without setup.company.manage', function () {
    $viewer = actingAsUserWith(['setup.company.view']);
    $name = testOrganization()->name;

    expect(companyAgent($viewer, 'update_company_profile', ['name' => 'Hijacked'])->detail)->toContain('permission')
        ->and(testOrganization()->fresh()->name)->toBe($name);
});

// ── What stays out ───────────────────────────────────────────────────────────

test('statutory numbers and the join code are never read, and no tool sets them', function () {
    $manager = actingAsUserWith(['setup.company.view', 'setup.company.manage']);
    $organization = testOrganization();
    $organization->forceFill(['tin' => '123-456-789-000', 'sss_employer_no' => null, 'philhealth_employer_no' => '99-887766554-3'])->save();
    $organization->rotateJoinCode();
    $code = $organization->fresh()->join_code;

    $read = json_encode([
        companyAgent($manager, 'get_company_profile')->cards,
        app(CompanyProfileModule::class)->tools($manager),
    ]);

    expect($read)->toContain('Statutory employer numbers on file: TIN, PhilHealth; missing: SSS, Pag-IBIG')
        ->toContain('Joining by company code: on')
        ->not->toContain('123-456-789')->not->toContain('887766554')->not->toContain($code)
        ->not->toContain('"tin"')->not->toContain('join_code')->not->toContain('"logo"');

    // Whether joining by code is on is for those who manage it, as on the screen.
    $viewer = actingAsUserWith(['setup.company.view']);

    expect(json_encode(companyAgent($viewer, 'get_company_profile')->cards))->not->toContain('Joining by company code');
});

// ── Reading ──────────────────────────────────────────────────────────────────

test('a question about the time zone reads the profile before the model is called', function () {
    $user = actingAsSuperAdmin();
    testOrganization()->forceFill(['timezone' => 'Asia/Manila', 'legal_name' => 'Acme Holdings, Inc.'])->save();

    $brief = app(Retriever::class)->retrieve($user, 'what time zone are we on?');

    expect($brief?->isAboutWorkspace())->toBeTrue()
        ->and($brief->toPrompt())->toContain('Time zone: Asia/Manila')->toContain('Registered as Acme Holdings, Inc.');
});

// ── Doing ────────────────────────────────────────────────────────────────────

test('names and contact details change by the screen’s rules, and are recorded', function () {
    $user = actingAsSuperAdmin();
    testOrganization()->forceFill(['phone' => '0917 000 0000'])->save();

    $changed = companyAgent($user, 'update_company_profile', ['legal_name' => 'Acme Holdings, Inc.', 'email' => 'hr@acme.test', 'clear' => ['phone']]);
    $badEmail = companyAgent($user, 'update_company_profile', ['email' => 'not-an-email']);
    $both = companyAgent($user, 'update_company_profile', ['phone' => '123', 'clear' => ['phone']]);

    $organization = testOrganization()->fresh();

    expect($changed->failed())->toBeFalse()
        ->and($organization->legal_name)->toBe('Acme Holdings, Inc.')
        ->and($organization->email)->toBe('hr@acme.test')
        ->and($organization->phone)->toBeNull()
        ->and($badEmail->failed())->toBeTrue()
        ->and($badEmail->detail)->toContain('email')
        ->and($both->failed())->toBeTrue()
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->latest('id')->value('description'))->toBe('Updated the company profile via assistant');
});

test('a time zone is named by zone or city, never by an offset, and the change is recorded', function () {
    $user = actingAsSuperAdmin();
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $offset = companyAgent($user, 'set_company_timezone', ['timezone' => 'UTC+8']);
    $unknown = companyAgent($user, 'set_company_timezone', ['timezone' => 'Atlantis']);
    $same = companyAgent($user, 'set_company_timezone', ['timezone' => 'asia/manila']);
    $city = companyAgent($user, 'set_company_timezone', ['timezone' => 'singapore']);

    expect($offset->failed())->toBeTrue()
        ->and($offset->detail)->toContain('offset')
        ->and($unknown->failed())->toBeTrue()
        ->and($same->detail)->toContain('already on Asia/Manila')
        ->and($city->failed())->toBeFalse()
        ->and(testOrganization()->fresh()->timezone)->toBe('Asia/Singapore')
        ->and(ActivityLog::query()->where('log_name', 'company-setup')->latest('id')->value('description'))
        ->toBe('Updated the company profile via assistant: time zone Asia/Manila → Asia/Singapore');
});

test('the confirmation says what a new time zone moves', function () {
    $user = actingAsSuperAdmin();
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $line = app(CompanyProfileModule::class)->consequence($user, 'set_company_timezone', ['timezone' => 'America/New_York']);

    expect($line)->toContain('America/New_York')->toContain('Asia/Manila')->toContain('days already recorded keep their times')
        ->and(app(CompanyProfileModule::class)->consequence($user, 'set_company_timezone', ['timezone' => 'nowhere']))->toBeNull();
});

test('the screen records a time zone change by name too', function () {
    actingAsSuperAdmin();
    testOrganization()->forceFill(['timezone' => 'Asia/Manila'])->save();

    $this->post(route('setup.company.update'), ['name' => 'Acme', 'timezone' => 'Europe/London'])->assertSessionHasNoErrors();

    expect(ActivityLog::query()->where('log_name', 'company-setup')->latest('id')->value('description'))
        ->toBe('Updated the company profile: time zone Asia/Manila → Europe/London');
});

test('only the workspace the user is in is ever read or changed', function () {
    $user = actingAsSuperAdmin();
    $other = Organization::factory()->create(['name' => 'Other Co', 'legal_name' => 'Other Co, Ltd.']);

    companyAgent($user, 'update_company_profile', ['name' => 'Renamed']);

    expect(json_encode(companyAgent($user, 'get_company_profile')->cards))->not->toContain('Other Co')
        ->and(testOrganization()->fresh()->name)->toBe('Renamed')
        ->and($other->fresh()->name)->toBe('Other Co');
});
