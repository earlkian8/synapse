<?php

use App\Http\Controllers\Training\MyTrainingController;
use App\Http\Controllers\Training\TrainingBudgetController;
use App\Http\Controllers\Training\TrainingCertificateController;
use App\Http\Controllers\Training\TrainingController;
use App\Http\Controllers\Training\TrainingCostController;
use App\Http\Controllers\Training\TrainingEnrollmentController;
use App\Http\Controllers\Training\TrainingExportController;
use App\Http\Controllers\Training\TrainingProgramController;
use App\Http\Controllers\Training\TrainingRosterExportController;
use App\Http\Controllers\Training\TrainingScheduleController;
use App\Http\Controllers\Training\TrainingSessionController;
use Illuminate\Support\Facades\Route;

/*
| Training & Development — the organisation's training programs and who is enrolled
| in each. Programs are created in-module (no Company Setup) and addressed by
| hashid; enrollments, sessions and costs by numeric id. Viewing needs
| `training.view`; creating programs / enrolling / grading needs `training.manage`.
|
| Taking part (`training.participate`, ADR 0074): one's own training, the
| programs one may join, joining and withdrawing. Sessions and attendance (ADR
| 0074), certificates and budgets (ADR 0075) are sections of their own. Every
| literal prefix is declared before the {trainingProgram} wildcard.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('training')
    ->name('training.')
    ->group(function () {
        // `training.view`, or `training.participate` (redirected to My training).
        Route::get('/', [TrainingController::class, 'index'])->name('index');
        Route::post('/', [TrainingProgramController::class, 'store'])->middleware('can:training.manage')->name('store');

        // CSV export of every program (literal — declared before the wildcard).
        Route::get('export', TrainingExportController::class)->middleware('can:training.view')->name('export');

        // My training and the programs one may join. An enrollment or session is
        // reachable here only by its own employee (anyone else gets a 404).
        Route::middleware('can:training.participate')->group(function () {
            Route::get('me', [MyTrainingController::class, 'index'])->name('me');
            Route::get('me/sessions/{session}/ics', [MyTrainingController::class, 'sessionIcs'])->name('me.session-ics');
            Route::delete('me/enrollments/{enrollment}', [MyTrainingController::class, 'withdraw'])->name('me.withdraw');
            Route::get('catalog', [MyTrainingController::class, 'catalog'])->name('catalog');
            Route::post('catalog/{trainingProgram}/join', [MyTrainingController::class, 'join'])->name('join');
        });

        // The training calendar, the certificate register and the budget.
        Route::get('schedule', TrainingScheduleController::class)->middleware('can:training.view')->name('schedule');
        Route::get('certificates', [TrainingCertificateController::class, 'index'])->middleware('can:training.view')->name('certificates.index');
        Route::get('certificates/export', [TrainingCertificateController::class, 'export'])->middleware('can:training.view')->name('certificates.export');
        // HR, or the certificate's own holder (checked in the controller).
        Route::get('certificates/{certificate}', [TrainingCertificateController::class, 'show'])->name('certificates.show');
        Route::get('budget', [TrainingBudgetController::class, 'index'])->middleware('can:training.view')->name('budget');
        Route::post('budget', [TrainingBudgetController::class, 'store'])->middleware('can:training.manage')->name('budget.store');

        // Enrollment mutations (literal "enrollments" — declared before the
        // {trainingProgram} wildcard so it never binds to it). The bulk route is
        // declared before the {enrollment} wildcard for the same reason.
        Route::patch('enrollments/bulk', [TrainingEnrollmentController::class, 'bulk'])->middleware('can:training.manage')->name('enrollments.bulk');
        Route::patch('enrollments/{enrollment}', [TrainingEnrollmentController::class, 'update'])->middleware('can:training.manage')->name('enrollments.update');
        Route::delete('enrollments/{enrollment}', [TrainingEnrollmentController::class, 'destroy'])->middleware('can:training.manage')->name('enrollments.destroy');
        Route::post('enrollments/{enrollment}/approve', [TrainingEnrollmentController::class, 'approve'])->middleware('can:training.manage')->name('enrollments.approve');
        Route::post('enrollments/{enrollment}/decline', [TrainingEnrollmentController::class, 'decline'])->middleware('can:training.manage')->name('enrollments.decline');

        // Sessions and attendance; costs.
        Route::patch('sessions/{session}', [TrainingSessionController::class, 'update'])->middleware('can:training.manage')->name('sessions.update');
        Route::delete('sessions/{session}', [TrainingSessionController::class, 'destroy'])->middleware('can:training.manage')->name('sessions.destroy');
        Route::put('sessions/{session}/attendance', [TrainingSessionController::class, 'attendance'])->middleware('can:training.manage')->name('sessions.attendance');
        Route::patch('costs/{cost}', [TrainingCostController::class, 'update'])->middleware('can:training.manage')->name('costs.update');
        Route::delete('costs/{cost}', [TrainingCostController::class, 'destroy'])->middleware('can:training.manage')->name('costs.destroy');

        // A single program and its roster.
        Route::get('{trainingProgram}', [TrainingController::class, 'show'])->middleware('can:training.view')->name('show');
        Route::get('{trainingProgram}/export', TrainingRosterExportController::class)->middleware('can:training.view')->name('roster-export');
        Route::post('{trainingProgram}/insights', [TrainingController::class, 'insights'])->middleware('can:training.view')->name('insights');
        Route::post('{trainingProgram}', [TrainingProgramController::class, 'update'])->middleware('can:training.manage')->name('update');
        Route::delete('{trainingProgram}', [TrainingProgramController::class, 'destroy'])->middleware('can:training.manage')->name('destroy');
        Route::patch('{trainingProgram}/restore', [TrainingProgramController::class, 'restore'])->middleware('can:training.manage')->name('restore');
        Route::delete('{trainingProgram}/force', [TrainingProgramController::class, 'forceDelete'])->middleware('can:training.manage')->name('force-delete');

        Route::post('{trainingProgram}/enrollments', [TrainingEnrollmentController::class, 'store'])->middleware('can:training.manage')->name('enrollments.store');
        Route::post('{trainingProgram}/sessions', [TrainingSessionController::class, 'store'])->middleware('can:training.manage')->name('sessions.store');
        Route::post('{trainingProgram}/costs', [TrainingCostController::class, 'store'])->middleware('can:training.manage')->name('costs.store');
    });
