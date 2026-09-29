<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Modules\SystemGuideModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationProvisioner;
use App\Support\Setup\CompanySetup;
use App\Support\SystemGuide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| The system guide (ADR 0059): how SYNAPSE works and where things are — always
| read for the asker, so a screen they cannot open is never described to them.
*/

function guideAgent(User $user, string $tool, array $args = []): ToolResult
{
    return app(SystemGuideModule::class)->run($user, $tool, $args);
}

test('help points at the right screen, and only at screens the asker can open', function () {
    $hr = actingAsSuperAdmin();
    $staff = actingAsUserWith(['leave.request', 'attendance.clock']);

    $invite = guideAgent($hr, 'find_help', ['question' => 'how do I invite an employee to the mobile app?']);
    $staffInvite = guideAgent($staff, 'find_help', ['question' => 'how do I invite an employee to the mobile app?']);
    $staffLeave = guideAgent($staff, 'find_help', ['question' => 'how do I file my leave?']);

    expect($invite->cards[0]['title'])->toBe('App access')
        ->and($invite->cards[0]['subtitle'])->toBe('/employees/access')
        ->and(collect($staffInvite->cards)->pluck('title'))->not->toContain('App access')
        ->and($staffLeave->cards[0]['title'])->toBe('Your own leave')
        ->and(SystemGuide::for($staff)->keys()->all())->not->toContain('users', 'roles', 'employees', 'attrition')
        ->and(app(Retriever::class)->retrieve($staff, 'where do i see my notifications?')?->toPrompt())
        ->toContain('Notifications (/system/notifications)')
        ->not->toContain('/system/users');
});

test('a user learns their own access and workspaces', function () {
    $organization = testOrganization();
    $user = actingAsUserWith(['leave.view', 'leave.manage']);
    $other = Organization::factory()->create(['name' => 'Second Co']);
    OrganizationProvisioner::addMember($other, $user);

    $access = guideAgent($user, 'get_my_access')->cards[0];
    $workspaces = guideAgent($user, 'list_my_workspaces');

    expect(implode(' | ', $access['meta']))->toContain('Can: Leave Management 2/3')
        ->toContain('Screens: Dashboard, Leave Management')
        ->and(collect($workspaces->cards)->pluck('title')->all())->toContain($organization->name, 'Second Co')
        ->and(collect($workspaces->cards)->firstWhere('title', $organization->name)['badge'])->toBe('Open now');
});

test('setup progress needs the company profile permission', function () {
    $organization = testOrganization();
    CompanySetup::markStep($organization, 'company', CompanySetup::DONE);
    $owner = actingAsUserWith(['setup.company.view']);

    $card = guideAgent($owner, 'get_setup_progress')->cards[0];

    expect($card['title'])->toBe('1 of 12 setup steps answered')
        ->and($card['meta'][0])->toBe('Company profile: done')
        ->and(guideAgent(actingAsUserWith(['leave.view']), 'get_setup_progress')->detail)->toContain("don't have permission");
});

test('every address in the guide is a real page', function () {
    $paths = SystemGuide::for(actingAsSuperAdmin())->pluck('path')->filter()->values();

    $unrouted = $paths->reject(function (string $path): bool {
        try {
            return Route::getRoutes()->match(Request::create($path, 'GET')) !== null;
        } catch (Throwable) {
            return false;
        }
    });

    expect($paths)->not->toBeEmpty()
        ->and($unrouted->all())->toBe([]);
});
