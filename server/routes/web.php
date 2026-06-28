<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Switch the active organisation (employees / admins of more than one company).
// Only `auth` — switching must work regardless of the new org's verification state.
Route::middleware('auth')->post('organization/switch', [OrganizationSwitchController::class, 'update'])
    ->name('organization.switch');

Route::middleware(['auth', 'verified'])->group(function () {
    // The post-login landing: pick which company to work in (skipped for users
    // with a single membership). See WorkspaceController.
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // The floating agentic assistant. Open to any authenticated user; the agent
    // exposes only the HR modules the user is permitted to use. Conversations are
    // persisted per user so history survives across sessions and devices.
    Route::post('assistant', [AssistantController::class, 'send'])->name('assistant');
    Route::get('assistant/conversations', [AssistantConversationController::class, 'index'])->name('assistant.conversations.index');
    Route::delete('assistant/conversations', [AssistantConversationController::class, 'clear'])->name('assistant.conversations.clear');
    Route::post('assistant/conversations/{conversation}/regenerate', [AssistantController::class, 'regenerate'])->name('assistant.regenerate');
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
require __DIR__.'/setup.php';
