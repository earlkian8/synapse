<?php

use App\Http\Controllers\Employee\EmployeeBulkActionController;
use App\Http\Controllers\Employee\EmployeeCertificationController;
use App\Http\Controllers\Employee\EmployeeController;
use App\Http\Controllers\Employee\EmployeeDocumentController;
use App\Http\Controllers\Employee\EmployeeExportController;
use App\Http\Controllers\Employee\EmployeeStatusController;
use Illuminate\Support\Facades\Route;

/*
| The Employee module — the HR hub. Every route is permission-gated; the bulk
| endpoint re-authorises each action inside the controller.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('employees')
    ->name('employees.')
    ->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('index');
        Route::get('export', EmployeeExportController::class)->middleware('can:employees.export')->name('export');
        Route::post('bulk', EmployeeBulkActionController::class)->middleware('can:employees.view')->name('bulk');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('can:employees.create')->name('store');

        Route::get('{employee}', [EmployeeController::class, 'show'])->middleware('can:employees.view')->name('show');
        Route::post('{employee}', [EmployeeController::class, 'update'])->middleware('can:employees.update')->name('update');
        Route::delete('{employee}', [EmployeeController::class, 'destroy'])->middleware('can:employees.delete')->name('destroy');
        Route::patch('{employee}/status', [EmployeeStatusController::class, 'update'])->middleware('can:employees.update')->name('status');
        Route::patch('{employee}/restore', [EmployeeController::class, 'restore'])->middleware('can:employees.restore')->name('restore');
        Route::delete('{employee}/force', [EmployeeController::class, 'forceDelete'])->middleware('can:employees.force-delete')->name('force-delete');

        // Owned sub-records (201 file).
        Route::post('{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('can:employees.manage-documents')->name('documents.store');
        Route::delete('{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('can:employees.manage-documents')->name('documents.destroy');
        Route::post('{employee}/certifications', [EmployeeCertificationController::class, 'store'])->middleware('can:employees.manage-documents')->name('certifications.store');
        Route::delete('{employee}/certifications/{certification}', [EmployeeCertificationController::class, 'destroy'])->middleware('can:employees.manage-documents')->name('certifications.destroy');
    });
