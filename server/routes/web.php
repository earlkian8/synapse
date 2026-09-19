<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantConversationController;
use App\Http\Controllers\Auth\VerifyEmailCodeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\Public\InvitationController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Where the invitation email's link lands. Public on purpose (ADR 0026): the
// recipient may not have an account anywhere yet. Shows who is inviting them and
// the code to type — it never redeems anything, which happens in the app.
Route::get('invite/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('invite.show');

// The web kiosk (ADR 0040): a shared tablet at the door. Public on purpose —
// nobody signs in on it. The page holds the device's key in the browser and
// every punch it records goes to the key-authenticated device API.
Route::inertia('kiosk', 'kiosk')->name('kiosk');

// Switch the active organisation (employees / admins of more than one company).
// Only `auth` — switching must work regardless of the new org's verification state.
Route::middleware('auth')->post('organization/switch', [OrganizationSwitchController::class, 'update'])
    ->name('organization.switch');

// Confirming an email address from the code it was sent. Fortify owns the screen
// (`verification.notice`) and the resend (`verification.send`); this is the third
// leg, which its link-based flow had no need of. Only `auth` — the caller is by
// definition not verified yet. Throttled because a six-digit code is only safe
// while guessing it is slow.
Route::middleware(['auth', 'throttle:6,1'])
    ->post('email/verify', [VerifyEmailCodeController::class, 'store'])
    ->name('verification.code');

Route::middleware(['auth', 'verified'])->group(function () {
    // The post-login landing: pick which company to work in (skipped for users
    // with a single membership). See WorkspaceController.
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // The floating agentic assistant. Open to any authenticated user; the agent
    // exposes only the HR modules the user is permitted to use. Conversations are
    // persisted per user so history survives across sessions and devices.
    // Throttled: a turn spends Gemini quota and fans out into tool calls, so
    // being signed in is not a licence to run it in a loop. See AppServiceProvider.
    Route::post('assistant', [AssistantController::class, 'send'])->middleware('throttle:assistant')->name('assistant');
    Route::get('assistant/conversations', [AssistantConversationController::class, 'index'])->name('assistant.conversations.index');
    Route::delete('assistant/conversations', [AssistantConversationController::class, 'clear'])->name('assistant.conversations.clear');
    Route::post('assistant/conversations/{conversation}/regenerate', [AssistantController::class, 'regenerate'])->middleware('throttle:assistant')->name('assistant.regenerate');
    Route::get('assistant/conversations/{conversation}', [AssistantConversationController::class, 'show'])->name('assistant.conversations.show');
    Route::patch('assistant/conversations/{conversation}', [AssistantConversationController::class, 'update'])->name('assistant.conversations.update');
    Route::delete('assistant/conversations/{conversation}', [AssistantConversationController::class, 'destroy'])->name('assistant.conversations.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/system.php';
require __DIR__.'/careers.php';
require __DIR__.'/employees.php';
require __DIR__.'/recruitment.php';
require __DIR__.'/onboarding.php';
require __DIR__.'/leave.php';
require __DIR__.'/attendance.php';
require __DIR__.'/performance.php';
require __DIR__.'/analytics.php';
require __DIR__.'/training.php';
require __DIR__.'/awards.php';
require __DIR__.'/events.php';
require __DIR__.'/offboarding.php';
require __DIR__.'/reports.php';
require __DIR__.'/setup.php';
