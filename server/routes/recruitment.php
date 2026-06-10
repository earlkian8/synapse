<?php

use App\Http\Controllers\Recruitment\ApplicantController;
use App\Http\Controllers\Recruitment\HireController;
use App\Http\Controllers\Recruitment\InterviewController;
use App\Http\Controllers\Recruitment\JobApplicationController;
use App\Http\Controllers\Recruitment\JobPostingController;
use App\Http\Controllers\Recruitment\JobPostingStatusController;
use App\Http\Controllers\Recruitment\RecruitmentExportController;
use Illuminate\Support\Facades\Route;

/*
| The Recruitment module — an applicant tracking system. Postings collect
| applications that move through a hiring pipeline; a hired application creates
| an Employee (the recruitment → workforce bridge). Every route is
| permission-gated. Literal-prefixed routes are declared before the
| `{jobPosting}` wildcard so they resolve correctly.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('recruitment')
    ->name('recruitment.')
    ->group(function () {
        Route::get('/', [JobPostingController::class, 'index'])->middleware('can:recruitment.view')->name('index');
        Route::get('export', RecruitmentExportController::class)->middleware('can:recruitment.export')->name('export');
        Route::post('/', [JobPostingController::class, 'store'])->middleware('can:recruitment.create')->name('store');

        // Candidate pool.
        Route::post('applicants', [ApplicantController::class, 'store'])->middleware('can:recruitment.create')->name('applicants.store');
        Route::post('applicants/{applicant}', [ApplicantController::class, 'update'])->middleware('can:recruitment.update')->name('applicants.update');
        Route::delete('applicants/{applicant}', [ApplicantController::class, 'destroy'])->middleware('can:recruitment.delete')->name('applicants.destroy');

        // Applications (pipeline).
        Route::get('applications/{application}', [JobApplicationController::class, 'show'])->middleware('can:recruitment.view')->name('applications.show');
        Route::post('applications/{application}', [JobApplicationController::class, 'update'])->middleware('can:recruitment.update')->name('applications.update');
        Route::delete('applications/{application}', [JobApplicationController::class, 'destroy'])->middleware('can:recruitment.manage-pipeline')->name('applications.destroy');
        Route::patch('applications/{application}/stage', [JobApplicationController::class, 'stage'])->middleware('can:recruitment.manage-pipeline')->name('applications.stage');
        Route::patch('applications/{application}/reject', [JobApplicationController::class, 'reject'])->middleware('can:recruitment.manage-pipeline')->name('applications.reject');
        Route::post('applications/{application}/hire', HireController::class)->middleware('can:recruitment.hire')->name('applications.hire');
        Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->middleware('can:recruitment.schedule-interviews')->name('interviews.store');

        // Interviews.
        Route::post('interviews/{interview}', [InterviewController::class, 'update'])->middleware('can:recruitment.schedule-interviews')->name('interviews.update');
        Route::delete('interviews/{interview}', [InterviewController::class, 'destroy'])->middleware('can:recruitment.schedule-interviews')->name('interviews.destroy');

        // A posting and its pipeline board (wildcard — declared last).
        Route::get('{jobPosting}', [JobPostingController::class, 'show'])->middleware('can:recruitment.view')->name('show');
        Route::post('{jobPosting}', [JobPostingController::class, 'update'])->middleware('can:recruitment.update')->name('update');
        Route::delete('{jobPosting}', [JobPostingController::class, 'destroy'])->middleware('can:recruitment.delete')->name('destroy');
        Route::patch('{jobPosting}/status', [JobPostingStatusController::class, 'update'])->middleware('can:recruitment.update')->name('status');
        Route::post('{jobPosting}/applications', [JobApplicationController::class, 'store'])->middleware('can:recruitment.create')->name('applications.store');
    });
