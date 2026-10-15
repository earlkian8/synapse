<?php

use App\Http\Controllers\Recruitment\ApplicantController;
use App\Http\Controllers\Recruitment\HireController;
use App\Http\Controllers\Recruitment\InterviewController;
use App\Http\Controllers\Recruitment\JobApplicationController;
use App\Http\Controllers\Recruitment\JobPostingController;
use App\Http\Controllers\Recruitment\JobPostingStatusController;
use App\Http\Controllers\Recruitment\MyInterviewsController;
use App\Http\Controllers\Recruitment\PipelineExportController;
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
        // The board; somebody who only interviews is sent to their interviews.
        Route::get('/', [JobPostingController::class, 'index'])->name('index');
        Route::get('export', RecruitmentExportController::class)->middleware('can:recruitment.export')->name('export');
        Route::post('/', [JobPostingController::class, 'store'])->middleware('can:recruitment.create')->name('store');

        // Candidate pool.
        Route::post('applicants', [ApplicantController::class, 'store'])->middleware('can:recruitment.create')->name('applicants.store');
        Route::post('applicants/{applicant}', [ApplicantController::class, 'update'])->middleware('can:recruitment.update')->name('applicants.update');
        Route::delete('applicants/{applicant}', [ApplicantController::class, 'destroy'])->middleware('can:recruitment.delete')->name('applicants.destroy');

        // Applications (pipeline).
        Route::get('applications/{application}', [JobApplicationController::class, 'show'])->middleware('can:recruitment.view')->name('applications.show');
        Route::post('applications/{application}/insights', [JobApplicationController::class, 'insights'])->middleware('can:recruitment.view')->name('applications.insights');
        Route::post('applications/{application}', [JobApplicationController::class, 'update'])->middleware('can:recruitment.update')->name('applications.update');
        Route::delete('applications/{application}', [JobApplicationController::class, 'destroy'])->middleware('can:recruitment.manage-pipeline')->name('applications.destroy');
        Route::patch('applications/{application}/stage', [JobApplicationController::class, 'stage'])->middleware('can:recruitment.manage-pipeline')->name('applications.stage');
        Route::patch('applications/{application}/reject', [JobApplicationController::class, 'reject'])->middleware('can:recruitment.manage-pipeline')->name('applications.reject');
        Route::post('applications/{application}/hire', HireController::class)->middleware('can:recruitment.hire')->name('applications.hire');
        Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->middleware('can:recruitment.schedule-interviews')->name('interviews.store');

        // Interviews. One's own (ADR 0091) — the interviews you are assigned —
        // and recording a verdict, which HR may do for any interview and an
        // interviewer for their own (checked in the controller).
        Route::get('interviews', [MyInterviewsController::class, 'index'])->middleware('can:recruitment.interview')->name('interviews.mine');
        Route::post('interviews/{interview}', [InterviewController::class, 'update'])->name('interviews.update');
        Route::delete('interviews/{interview}', [InterviewController::class, 'destroy'])->middleware('can:recruitment.schedule-interviews')->name('interviews.destroy');

        // A posting and its pipeline board (wildcard — declared last). The
        // two-segment export route is matched ahead of the bare wildcard.
        Route::get('{jobPosting}/export', PipelineExportController::class)->middleware('can:recruitment.export')->name('pipeline.export');
        Route::get('{jobPosting}', [JobPostingController::class, 'show'])->middleware('can:recruitment.view')->name('show');
        Route::post('{jobPosting}', [JobPostingController::class, 'update'])->middleware('can:recruitment.update')->name('update');
        Route::delete('{jobPosting}', [JobPostingController::class, 'destroy'])->middleware('can:recruitment.delete')->name('destroy');
        Route::patch('{jobPosting}/status', [JobPostingStatusController::class, 'update'])->middleware('can:recruitment.update')->name('status');
        Route::post('{jobPosting}/applications', [JobApplicationController::class, 'store'])->middleware('can:recruitment.create')->name('applications.store');
    });
