<?php

use App\Http\Controllers\Setup\DepartmentController;
use App\Http\Controllers\Setup\LeaveTypeController;
use App\Http\Controllers\Setup\PositionController;
use Illuminate\Support\Facades\Route;

/*
| Company Setup — the configuration layer the operational modules read from.
| First surface: the org structure (departments hierarchy + positions). Every
| route is permission-gated. Departments are addressed by hashid; positions by
| numeric id (sub-resources). Restore / force-delete take the hashid as a plain
| string so they can resolve archived rows.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('setup')
    ->name('setup.')
    ->group(function () {
        // Departments (org structure).
        Route::get('departments', [DepartmentController::class, 'index'])->middleware('can:setup.departments.view')->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->middleware('can:setup.departments.manage')->name('departments.store');
        Route::get('departments/{department}', [DepartmentController::class, 'show'])->middleware('can:setup.departments.view')->name('departments.show');
        Route::post('departments/{department}', [DepartmentController::class, 'update'])->middleware('can:setup.departments.manage')->name('departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware('can:setup.departments.manage')->name('departments.destroy');
        Route::patch('departments/{department}/restore', [DepartmentController::class, 'restore'])->middleware('can:setup.departments.manage')->name('departments.restore');
        Route::delete('departments/{department}/force', [DepartmentController::class, 'forceDelete'])->middleware('can:setup.departments.manage')->name('departments.force-delete');

        // Positions (under a department).
        Route::post('departments/{department}/positions', [PositionController::class, 'store'])->middleware('can:setup.departments.manage')->name('positions.store');
        Route::post('positions/{position}', [PositionController::class, 'update'])->middleware('can:setup.departments.manage')->name('positions.update');
        Route::delete('positions/{position}', [PositionController::class, 'destroy'])->middleware('can:setup.departments.manage')->name('positions.destroy');

        // Leave types (the kinds of leave the org grants). Addressed by hashid;
        // restore / force-delete take the hashid as a plain string.
        Route::get('leave-types', [LeaveTypeController::class, 'index'])->middleware('can:setup.leave-types.view')->name('leave-types.index');
        Route::post('leave-types', [LeaveTypeController::class, 'store'])->middleware('can:setup.leave-types.manage')->name('leave-types.store');
        Route::post('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->middleware('can:setup.leave-types.manage')->name('leave-types.update');
        Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->middleware('can:setup.leave-types.manage')->name('leave-types.destroy');
        Route::patch('leave-types/{leaveType}/restore', [LeaveTypeController::class, 'restore'])->middleware('can:setup.leave-types.manage')->name('leave-types.restore');
        Route::delete('leave-types/{leaveType}/force', [LeaveTypeController::class, 'forceDelete'])->middleware('can:setup.leave-types.manage')->name('leave-types.force-delete');
    });
