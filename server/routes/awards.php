<?php

use App\Http\Controllers\Awards\AwardController;
use App\Http\Controllers\Awards\AwardExportController;
use App\Http\Controllers\Awards\AwardNominationController;
use App\Http\Controllers\Awards\EmployeeAwardController;
use Illuminate\Support\Facades\Route;

/*
| Awards & Recognition — the recognition feed: every award given, with the lookups
| to grant a new one, plus the nomination board (decision support ranking who
| deserves each award). Award types are configured in Company Setup. Awards are
| addressed by numeric id. Viewing needs `awards.view`; giving / editing /
| removing — and the nomination board, since it ranks employees against each
| other — needs `awards.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('awards')
    ->name('awards.')
    ->group(function () {
        Route::get('/', [AwardController::class, 'index'])->middleware('can:awards.view')->name('index');
        Route::post('/', [EmployeeAwardController::class, 'store'])->middleware('can:awards.manage')->name('store');

        // Literal routes — declared before the {employeeAward} wildcard.
        Route::get('export', AwardExportController::class)->middleware('can:awards.view')->name('export');
        Route::get('nominations', [AwardNominationController::class, 'index'])->middleware('can:awards.manage')->name('nominations');
        Route::post('citation', [AwardNominationController::class, 'citation'])->middleware('can:awards.manage')->name('citation');

        Route::patch('{employeeAward}', [EmployeeAwardController::class, 'update'])->middleware('can:awards.manage')->name('update');
        Route::delete('{employeeAward}', [EmployeeAwardController::class, 'destroy'])->middleware('can:awards.manage')->name('destroy');
    });
