<?php

use App\Http\Controllers\Help\HelpCenterController;
use App\Http\Controllers\Support\ProblemReportController;
use Illuminate\Support\Facades\Route;

/*
| The Help Center (ADR 0062) — SYNAPSE's user manual. Open to everybody signed
| in; each article is shown only to people who can open the screen it explains,
| so there is no permission on the routes themselves. The literal routes come
| before the {category} wildcard.
*/

Route::middleware(['auth', 'verified'])
    ->prefix('help')
    ->name('help.')
    ->group(function () {
        Route::get('/', [HelpCenterController::class, 'index'])->name('index');
        Route::get('search', [HelpCenterController::class, 'search'])->name('search');
        Route::get('for', [HelpCenterController::class, 'forPage'])->name('for-page');
        // Problems reported to the SYNAPSE team (ADR 0096): one's own, and
        // sending one from the dialog any page opens (JSON, see bootstrap/app.php).
        Route::get('reports', [ProblemReportController::class, 'index'])->name('reports.index');
        Route::post('reports', [ProblemReportController::class, 'store'])
            ->middleware('throttle:problem-reports')
            ->name('reports.store');
        Route::get('{category}', [HelpCenterController::class, 'category'])
            ->where('category', '[a-z0-9-]+')
            ->name('category');
        Route::get('{category}/{article}', [HelpCenterController::class, 'show'])
            ->where(['category' => '[a-z0-9-]+', 'article' => '[a-z0-9-]+'])
            ->name('article');
    });
