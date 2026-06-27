<?php

use App\Http\Controllers\Setup\AwardTypeController;
use App\Http\Controllers\Setup\CompanyProfileController;
use App\Http\Controllers\Setup\DepartmentController;
use App\Http\Controllers\Setup\EvaluationPeriodController;
use App\Http\Controllers\Setup\HolidayController;
use App\Http\Controllers\Setup\KpiCriterionController;
use App\Http\Controllers\Setup\KpiSetupController;
use App\Http\Controllers\Setup\LeaveTypeController;
use App\Http\Controllers\Setup\PositionController;
use App\Http\Controllers\Setup\ScheduleSetupController;
use App\Http\Controllers\Setup\WorkScheduleController;
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
        // Company Profile — the organisation's own identity, contact details and
        // statutory employer numbers (the tenant doubles as the company profile).
        Route::get('company', [CompanyProfileController::class, 'edit'])->middleware('can:setup.company.view')->name('company.edit');
        Route::post('company', [CompanyProfileController::class, 'update'])->middleware('can:setup.company.manage')->name('company.update');

        // Work Schedule & Holidays — the shifts employees follow (read by
        // Attendance) and the holiday calendar (read by Leave). Both addressed by
        // hashid; restore / force-delete take the hashid as a string.
        Route::get('schedule', [ScheduleSetupController::class, 'index'])->middleware('can:setup.schedule.view')->name('schedule.index');

        Route::post('schedule/work-schedules', [WorkScheduleController::class, 'store'])->middleware('can:setup.schedule.manage')->name('schedule.work-schedules.store');
        Route::post('schedule/work-schedules/{workSchedule}', [WorkScheduleController::class, 'update'])->middleware('can:setup.schedule.manage')->name('schedule.work-schedules.update');
        Route::delete('schedule/work-schedules/{workSchedule}', [WorkScheduleController::class, 'destroy'])->middleware('can:setup.schedule.manage')->name('schedule.work-schedules.destroy');
        Route::patch('schedule/work-schedules/{workSchedule}/restore', [WorkScheduleController::class, 'restore'])->middleware('can:setup.schedule.manage')->name('schedule.work-schedules.restore');
        Route::delete('schedule/work-schedules/{workSchedule}/force', [WorkScheduleController::class, 'forceDelete'])->middleware('can:setup.schedule.manage')->name('schedule.work-schedules.force-delete');

        Route::post('schedule/holidays', [HolidayController::class, 'store'])->middleware('can:setup.schedule.manage')->name('schedule.holidays.store');
        Route::post('schedule/holidays/{holiday}', [HolidayController::class, 'update'])->middleware('can:setup.schedule.manage')->name('schedule.holidays.update');
        Route::delete('schedule/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('can:setup.schedule.manage')->name('schedule.holidays.destroy');
        Route::patch('schedule/holidays/{holiday}/restore', [HolidayController::class, 'restore'])->middleware('can:setup.schedule.manage')->name('schedule.holidays.restore');
        Route::delete('schedule/holidays/{holiday}/force', [HolidayController::class, 'forceDelete'])->middleware('can:setup.schedule.manage')->name('schedule.holidays.force-delete');

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

        // KPI & Evaluation Criteria — the weighted criteria and review periods the
        // Performance Management module evaluates against. Both addressed by hashid;
        // restore / force take it as a string.
        Route::get('kpi', [KpiSetupController::class, 'index'])->middleware('can:setup.kpi.view')->name('kpi.index');

        Route::post('kpi/criteria', [KpiCriterionController::class, 'store'])->middleware('can:setup.kpi.manage')->name('kpi.criteria.store');
        Route::post('kpi/criteria/{kpiCriterion}', [KpiCriterionController::class, 'update'])->middleware('can:setup.kpi.manage')->name('kpi.criteria.update');
        Route::delete('kpi/criteria/{kpiCriterion}', [KpiCriterionController::class, 'destroy'])->middleware('can:setup.kpi.manage')->name('kpi.criteria.destroy');
        Route::patch('kpi/criteria/{kpiCriterion}/restore', [KpiCriterionController::class, 'restore'])->middleware('can:setup.kpi.manage')->name('kpi.criteria.restore');
        Route::delete('kpi/criteria/{kpiCriterion}/force', [KpiCriterionController::class, 'forceDelete'])->middleware('can:setup.kpi.manage')->name('kpi.criteria.force-delete');

        Route::post('kpi/periods', [EvaluationPeriodController::class, 'store'])->middleware('can:setup.kpi.manage')->name('kpi.periods.store');
        Route::post('kpi/periods/{evaluationPeriod}', [EvaluationPeriodController::class, 'update'])->middleware('can:setup.kpi.manage')->name('kpi.periods.update');
        Route::delete('kpi/periods/{evaluationPeriod}', [EvaluationPeriodController::class, 'destroy'])->middleware('can:setup.kpi.manage')->name('kpi.periods.destroy');
        Route::patch('kpi/periods/{evaluationPeriod}/restore', [EvaluationPeriodController::class, 'restore'])->middleware('can:setup.kpi.manage')->name('kpi.periods.restore');
        Route::delete('kpi/periods/{evaluationPeriod}/force', [EvaluationPeriodController::class, 'forceDelete'])->middleware('can:setup.kpi.manage')->name('kpi.periods.force-delete');

        // Award Types — the catalogue of recognitions the Awards & Recognition
        // module gives out. Addressed by hashid; restore / force take it as a string.
        Route::get('award-types', [AwardTypeController::class, 'index'])->middleware('can:setup.award-types.view')->name('award-types.index');
        Route::post('award-types', [AwardTypeController::class, 'store'])->middleware('can:setup.award-types.manage')->name('award-types.store');
        Route::post('award-types/{awardType}', [AwardTypeController::class, 'update'])->middleware('can:setup.award-types.manage')->name('award-types.update');
        Route::delete('award-types/{awardType}', [AwardTypeController::class, 'destroy'])->middleware('can:setup.award-types.manage')->name('award-types.destroy');
        Route::patch('award-types/{awardType}/restore', [AwardTypeController::class, 'restore'])->middleware('can:setup.award-types.manage')->name('award-types.restore');
        Route::delete('award-types/{awardType}/force', [AwardTypeController::class, 'forceDelete'])->middleware('can:setup.award-types.manage')->name('award-types.force-delete');
    });
