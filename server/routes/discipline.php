<?php

use App\Http\Controllers\Discipline\ConductCodeController;
use App\Http\Controllers\Discipline\DisciplineController;
use App\Http\Controllers\Discipline\MyDisciplineController;
use Illuminate\Support\Facades\Route;

/*
| Discipline (ADR 0105) — incidents and the twin-notice due process of D.O.
| 147-15, the code of conduct, and each employee's conduct record. Cases are
| addressed by hashid. HR sees with `discipline.view` and acts with
| `discipline.manage`; a supervisor reports with `discipline.report`; everyone
| answers the notices served on them with `discipline.respond`. The landing page
| sends each to theirs. Literal segments are declared before the wildcards.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('discipline')
    ->name('discipline.')
    ->group(function () {
        Route::get('/', [DisciplineController::class, 'index'])->name('index');

        // Reporting an incident, and following what one filed.
        Route::post('cases', [DisciplineController::class, 'store'])->middleware(['can:discipline.report', 'throttle:30,1'])->name('cases.store');
        Route::get('reported', [DisciplineController::class, 'reported'])->middleware('can:discipline.report')->name('reported');

        // My notices.
        Route::middleware('can:discipline.respond')->prefix('me')->name('me.')->group(function () {
            Route::get('/', [MyDisciplineController::class, 'index'])->name('index');
            Route::get('{disciplinaryCase}', [MyDisciplineController::class, 'show'])->name('show');
            Route::patch('{disciplinaryCase}/acknowledge', [MyDisciplineController::class, 'acknowledge'])->name('acknowledge');
            Route::post('{disciplinaryCase}/explanation', [MyDisciplineController::class, 'explain'])->middleware('throttle:20,1')->name('explain');
            Route::get('{disciplinaryCase}/notice.pdf', [MyDisciplineController::class, 'noticePdf'])->name('notice-pdf');
            Route::get('{disciplinaryCase}/decision.pdf', [MyDisciplineController::class, 'decisionPdf'])->name('decision-pdf');
        });

        // The code of conduct.
        Route::middleware('can:discipline.manage')->prefix('code')->name('code.')->group(function () {
            Route::get('/', [ConductCodeController::class, 'index'])->name('index');
            Route::post('/', [ConductCodeController::class, 'store'])->name('store');
            Route::post('standard', [ConductCodeController::class, 'standard'])->name('standard');
            Route::post('{conductOffense}', [ConductCodeController::class, 'update'])->name('update');
            Route::delete('{conductOffense}', [ConductCodeController::class, 'destroy'])->name('destroy');
        });

        // HR's side.
        Route::middleware('can:discipline.view')->group(function () {
            Route::get('cases', [DisciplineController::class, 'cases'])->name('cases.index');
            Route::get('people/{employee}', [DisciplineController::class, 'record'])->name('record');
            Route::get('cases/{disciplinaryCase}', [DisciplineController::class, 'show'])->name('cases.show');
            Route::get('cases/{disciplinaryCase}/notice.pdf', [DisciplineController::class, 'noticePdf'])->name('cases.notice-pdf');
            Route::get('cases/{disciplinaryCase}/decision.pdf', [DisciplineController::class, 'decisionPdf'])->name('cases.decision-pdf');
        });

        Route::middleware('can:discipline.manage')->group(function () {
            Route::post('cases/{disciplinaryCase}', [DisciplineController::class, 'update'])->name('cases.update');
            Route::post('cases/{disciplinaryCase}/attachments', [DisciplineController::class, 'attach'])->name('cases.attach');
            Route::delete('attachments/{disciplinaryCaseAttachment}', [DisciplineController::class, 'detach'])->name('attachments.destroy');
            Route::post('cases/{disciplinaryCase}/notice', [DisciplineController::class, 'notice'])->name('cases.notice');
            Route::post('cases/{disciplinaryCase}/explanation', [DisciplineController::class, 'explanation'])->name('cases.explanation');
            Route::post('cases/{disciplinaryCase}/hearing', [DisciplineController::class, 'hearing'])->name('cases.hearing');
            Route::post('cases/{disciplinaryCase}/hearing/record', [DisciplineController::class, 'recordHearing'])->name('cases.hearing.record');
            Route::post('cases/{disciplinaryCase}/preventive', [DisciplineController::class, 'preventive'])->name('cases.preventive');
            Route::post('cases/{disciplinaryCase}/decision', [DisciplineController::class, 'decide'])->name('cases.decide');
            Route::patch('cases/{disciplinaryCase}/close', [DisciplineController::class, 'close'])->name('cases.close');
            Route::patch('cases/{disciplinaryCase}/withdraw', [DisciplineController::class, 'withdraw'])->name('cases.withdraw');
        });
    });
