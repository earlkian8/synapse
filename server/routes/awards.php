<?php

use App\Http\Controllers\Awards\AwardController;
use App\Http\Controllers\Awards\AwardExportController;
use App\Http\Controllers\Awards\AwardNominationController;
use App\Http\Controllers\Awards\EmployeeAwardController;
use App\Http\Controllers\Awards\NominationReviewController;
use App\Http\Controllers\Awards\RewardController;
use App\Http\Controllers\Recognition\KudosController;
use App\Http\Controllers\Recognition\NominationController;
use App\Http\Controllers\Recognition\RecognitionController;
use App\Http\Controllers\Recognition\RedemptionController;
use Illuminate\Support\Facades\Route;

/*
| Awards & Recognition — the recognition feed: every award given, with the lookups
| to grant a new one, plus the nomination board (decision support ranking who
| deserves each award). Award types are configured in Company Setup. Awards are
| addressed by numeric id. Viewing needs `awards.view`; giving / editing /
| removing — and the nomination board, since it ranks employees against each
| other — needs `awards.manage`.
|
| Recognition for everybody (ADR 0071) lives in the same module: the wall of
| kudos and awards, one's points and rewards, and one's nominations — taking
| part needs `awards.participate`. The module's landing page sends someone who
| only takes part to the wall.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('awards')
    ->name('awards.')
    ->group(function () {
        // `awards.view`, or `awards.participate` (redirected to the wall).
        Route::get('/', [AwardController::class, 'index'])->name('index');
        Route::post('/', [EmployeeAwardController::class, 'store'])->middleware('can:awards.manage')->name('store');

        // Literal routes — declared before the {employeeAward} wildcard.
        Route::get('export', AwardExportController::class)->middleware('can:awards.view')->name('export');
        // The AI-ranked shortlist (it was "Nominations" before ADR 0071).
        Route::get('shortlist', [AwardNominationController::class, 'index'])->middleware('can:awards.manage')->name('shortlist');
        Route::post('citation', [AwardNominationController::class, 'citation'])->middleware('can:awards.manage')->name('citation');

        // Nominations colleagues made, to approve or turn down (ADR 0071).
        Route::middleware('can:awards.manage')->group(function () {
            Route::get('nominations', [NominationReviewController::class, 'index'])->name('nominations');
            Route::post('nominations/{nomination}/approve', [NominationReviewController::class, 'approve'])->whereNumber('nomination')->name('nominations.approve');
            Route::post('nominations/{nomination}/reject', [NominationReviewController::class, 'reject'])->whereNumber('nomination')->name('nominations.reject');

            // The rewards desk: catalogue (by hashid), requests, adjustments, kudos settings.
            Route::get('rewards', [RewardController::class, 'index'])->name('rewards');
            Route::post('rewards', [RewardController::class, 'store'])->name('rewards.store');
            Route::post('rewards/{reward}', [RewardController::class, 'update'])->name('rewards.update');
            Route::delete('rewards/{reward}', [RewardController::class, 'destroy'])->name('rewards.destroy');
            Route::patch('rewards/{reward}/restore', [RewardController::class, 'restore'])->name('rewards.restore');
            Route::post('redemptions/{redemption}/fulfil', [RewardController::class, 'fulfil'])->whereNumber('redemption')->name('redemptions.fulfil');
            Route::post('redemptions/{redemption}/decline', [RewardController::class, 'decline'])->whereNumber('redemption')->name('redemptions.decline');
            Route::post('points/adjust', [RewardController::class, 'adjust'])->name('points.adjust');
            Route::post('settings', [RewardController::class, 'settings'])->name('recognition-settings');
        });

        // Taking part (ADR 0071): the wall, one's points and nominations.
        // Nominations, kudos and requests by id; rewards by hashid.
        Route::middleware('can:awards.participate')->group(function () {
            Route::get('wall', [RecognitionController::class, 'index'])->name('wall');
            Route::get('points', [RecognitionController::class, 'rewards'])->name('points');
            Route::get('my-nominations', [RecognitionController::class, 'nominations'])->name('my-nominations');

            Route::post('kudos', [KudosController::class, 'store'])->middleware('throttle:30,1')->name('kudos.store');
            Route::post('my-nominations', [NominationController::class, 'store'])->middleware('throttle:20,1')->name('my-nominations.store');
            Route::delete('my-nominations/{nomination}', [NominationController::class, 'destroy'])->whereNumber('nomination')->name('my-nominations.destroy');
            Route::post('rewards/{reward}/redeem', [RedemptionController::class, 'store'])->middleware('throttle:20,1')->name('rewards.redeem');
            Route::patch('redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->whereNumber('redemption')->name('redemptions.cancel');
        });

        Route::delete('kudos/{kudos}', [KudosController::class, 'destroy'])->whereNumber('kudos')->middleware('can:awards.manage')->name('kudos.destroy');

        Route::patch('{employeeAward}', [EmployeeAwardController::class, 'update'])->middleware('can:awards.manage')->name('update');
        Route::delete('{employeeAward}', [EmployeeAwardController::class, 'destroy'])->middleware('can:awards.manage')->name('destroy');
    });
