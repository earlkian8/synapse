<?php

use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // The floating agentic assistant. Open to any authenticated user; the agent
    // exposes only the HR modules the user is permitted to use.
    Route::post('assistant', AssistantController::class)->name('assistant');
});

require __DIR__.'/settings.php';
require __DIR__.'/system.php';
require __DIR__.'/employees.php';
require __DIR__.'/recruitment.php';
require __DIR__.'/onboarding.php';
require __DIR__.'/leave.php';
require __DIR__.'/setup.php';
