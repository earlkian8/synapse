<?php

use App\Http\Controllers\Awards\AwardController;
use App\Http\Controllers\Awards\EmployeeAwardController;
use Illuminate\Support\Facades\Route;

/*
| Awards & Recognition — the recognition feed: every award given, with the lookups
| to grant a new one. Award types are configured in Company Setup. Awards are
| addressed by numeric id. Viewing needs `awards.view`; giving / editing / removing
| needs `awards.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('awards')
    ->name('awards.')
    ->group(function () {
        Route::get('/', [AwardController::class, 'index'])->middleware('can:awards.view')->name('index');
        Route::post('/', [EmployeeAwardController::class, 'store'])->middleware('can:awards.manage')->name('store');
        Route::patch('{employeeAward}', [EmployeeAwardController::class, 'update'])->middleware('can:awards.manage')->name('update');
        Route::delete('{employeeAward}', [EmployeeAwardController::class, 'destroy'])->middleware('can:awards.manage')->name('destroy');
    });
