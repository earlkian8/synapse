<?php

use App\Http\Controllers\Training\TrainingController;
use App\Http\Controllers\Training\TrainingEnrollmentController;
use App\Http\Controllers\Training\TrainingExportController;
use App\Http\Controllers\Training\TrainingProgramController;
use App\Http\Controllers\Training\TrainingRosterExportController;
use Illuminate\Support\Facades\Route;

/*
| Training & Development — the organisation's training programs and who is enrolled
| in each. Programs are created in-module (no Company Setup) and addressed by
| hashid; enrollments by numeric id. Viewing needs `training.view`; creating
| programs / enrolling / grading needs `training.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('training')
    ->name('training.')
    ->group(function () {
        Route::get('/', [TrainingController::class, 'index'])->middleware('can:training.view')->name('index');
        Route::post('/', [TrainingProgramController::class, 'store'])->middleware('can:training.manage')->name('store');

        // CSV export of every program (literal — declared before the wildcard).
        Route::get('export', TrainingExportController::class)->middleware('can:training.view')->name('export');

        // Enrollment mutations (literal "enrollments" — declared before the
        // {trainingProgram} wildcard so it never binds to it). The bulk route is
        // declared before the {enrollment} wildcard for the same reason.
        Route::patch('enrollments/bulk', [TrainingEnrollmentController::class, 'bulk'])->middleware('can:training.manage')->name('enrollments.bulk');
        Route::patch('enrollments/{enrollment}', [TrainingEnrollmentController::class, 'update'])->middleware('can:training.manage')->name('enrollments.update');
        Route::delete('enrollments/{enrollment}', [TrainingEnrollmentController::class, 'destroy'])->middleware('can:training.manage')->name('enrollments.destroy');

        // A single program and its roster.
        Route::get('{trainingProgram}', [TrainingController::class, 'show'])->middleware('can:training.view')->name('show');
        Route::get('{trainingProgram}/export', TrainingRosterExportController::class)->middleware('can:training.view')->name('roster-export');
        Route::post('{trainingProgram}/insights', [TrainingController::class, 'insights'])->middleware('can:training.view')->name('insights');
        Route::post('{trainingProgram}', [TrainingProgramController::class, 'update'])->middleware('can:training.manage')->name('update');
        Route::delete('{trainingProgram}', [TrainingProgramController::class, 'destroy'])->middleware('can:training.manage')->name('destroy');
        Route::patch('{trainingProgram}/restore', [TrainingProgramController::class, 'restore'])->middleware('can:training.manage')->name('restore');
        Route::delete('{trainingProgram}/force', [TrainingProgramController::class, 'forceDelete'])->middleware('can:training.manage')->name('force-delete');

        Route::post('{trainingProgram}/enrollments', [TrainingEnrollmentController::class, 'store'])->middleware('can:training.manage')->name('enrollments.store');
    });
