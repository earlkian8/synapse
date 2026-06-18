<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AssistantConversationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

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
require __DIR__.'/payroll.php';
require __DIR__.'/benefits.php';
require __DIR__.'/performance.php';
require __DIR__.'/setup.php';
