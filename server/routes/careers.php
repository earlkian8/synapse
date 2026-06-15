<?php

use App\Http\Controllers\Public\CareersController;
use Illuminate\Support\Facades\Route;

/*
| The public careers surface — unauthenticated. Each organisation has a board of
| open roles at /careers/{slug}; every open posting has a shareable page where a
| candidate applies with their CV and supporting files. No auth/tenant middleware:
| the controller resolves the organisation from the URL and stamps the submission
| with it. The apply route is rate-limited and honeypot-guarded against spam.
*/
Route::prefix('careers')->name('careers.')->group(function () {
    Route::get('/', [CareersController::class, 'landing'])->name('landing');
    Route::get('{organization:slug}', [CareersController::class, 'board'])->name('board');
    Route::get('{organization:slug}/jobs/{jobPosting}', [CareersController::class, 'show'])->name('show');
    Route::post('{organization:slug}/jobs/{jobPosting}/apply', [CareersController::class, 'apply'])
        ->middleware('throttle:5,1')
        ->name('apply');
});
