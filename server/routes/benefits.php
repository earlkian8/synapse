<?php

use App\Http\Controllers\Benefits\BenefitController;
use App\Http\Controllers\Benefits\BenefitEnrollmentController;
use Illuminate\Support\Facades\Route;

/*
| Benefits Administration — the live benefit program: an overview of every plan
| with its enrollment headcount + cost, and a plan's roster of enrolled employees.
| Plans are addressed by hashid; enrollments by numeric id. Viewing needs
| `benefits.view`; enrolling / editing needs `benefits.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('benefits')
    ->name('benefits.')
    ->group(function () {
        Route::get('/', [BenefitController::class, 'index'])->middleware('can:benefits.view')->name('index');

        // Enrollment mutations (literal "enrollments" — declared before the
        // {benefitPlan} wildcard so it never binds to it).
        Route::patch('enrollments/{enrollment}', [BenefitEnrollmentController::class, 'update'])->middleware('can:benefits.manage')->name('enrollments.update');
        Route::delete('enrollments/{enrollment}', [BenefitEnrollmentController::class, 'destroy'])->middleware('can:benefits.manage')->name('enrollments.destroy');

        // A single plan and its roster.
        Route::get('{benefitPlan}', [BenefitController::class, 'show'])->middleware('can:benefits.view')->name('show');
        Route::post('{benefitPlan}/enrollments', [BenefitEnrollmentController::class, 'store'])->middleware('can:benefits.manage')->name('enrollments.store');
    });
