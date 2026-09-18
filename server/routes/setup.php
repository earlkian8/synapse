<?php

use App\Http\Controllers\Onboarding\OnboardingProgramController;
use App\Http\Controllers\Recruitment\RecruitmentPipelineController;
use App\Http\Controllers\Setup\AttendancePolicyController;
use App\Http\Controllers\Setup\AwardTypeController;
use App\Http\Controllers\Setup\CompanyProfileController;
use App\Http\Controllers\Setup\DepartmentController;
use App\Http\Controllers\Setup\EvaluationPeriodController;
use App\Http\Controllers\Setup\HolidayController;
use App\Http\Controllers\Setup\JoinCodeController;
use App\Http\Controllers\Setup\KpiCriterionController;
use App\Http\Controllers\Setup\KpiSetupController;
use App\Http\Controllers\Setup\LeaveTypeController;
use App\Http\Controllers\Setup\OffboardingProgramController;
use App\Http\Controllers\Setup\PositionController;
use App\Http\Controllers\Setup\RatingScaleController;
use App\Http\Controllers\Setup\ReviewTemplateController;
use App\Http\Controllers\Setup\ScheduleSetupController;
use App\Http\Controllers\Setup\SetupWizardController;
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
        // The guided setup a brand-new company is taken through before its
        // dashboard (see SetupWizardController and RequireCompanySetup). Reaching
        // it is the company-profile ability; each step is gated by the ability of
        // the module it configures, because a step configures that module for
        // real — the wizard is a route through Company Setup, not a way around it.
        Route::get('wizard', [SetupWizardController::class, 'show'])->middleware('can:setup.company.manage')->name('wizard.show');
        Route::post('wizard/company', [SetupWizardController::class, 'company'])->middleware('can:setup.company.manage')->name('wizard.company');
        Route::post('wizard/departments', [SetupWizardController::class, 'departments'])->middleware('can:setup.departments.manage')->name('wizard.departments');
        Route::post('wizard/leave-types', [SetupWizardController::class, 'leaveTypes'])->middleware('can:setup.leave-types.manage')->name('wizard.leave-types');
        Route::post('wizard/attendance', [SetupWizardController::class, 'attendance'])->middleware('can:setup.attendance-policies.manage')->name('wizard.attendance');
        Route::post('wizard/recruitment', [SetupWizardController::class, 'recruitment'])->middleware('can:recruitment.configure-pipelines')->name('wizard.recruitment');
        Route::post('wizard/performance', [SetupWizardController::class, 'performance'])->middleware('can:setup.kpi.manage')->name('wizard.performance');
        Route::post('wizard/skip', [SetupWizardController::class, 'skip'])->middleware('can:setup.company.manage')->name('wizard.skip');
        Route::post('wizard/finish', [SetupWizardController::class, 'finish'])->middleware('can:setup.company.manage')->name('wizard.finish');

        // Company Profile — the organisation's own identity, contact details and
        // statutory employer numbers (the tenant doubles as the company profile).
        Route::get('company', [CompanyProfileController::class, 'edit'])->middleware('can:setup.company.view')->name('company.edit');
        Route::post('company', [CompanyProfileController::class, 'update'])->middleware('can:setup.company.manage')->name('company.update');

        // The company join code (ADR 0026) — a credential rather than a profile
        // field, so it rotates and toggles rather than being edited.
        Route::post('company/join-code', [JoinCodeController::class, 'rotate'])->middleware('can:setup.company.manage')->name('company.join-code.rotate');
        Route::patch('company/join-code', [JoinCodeController::class, 'update'])->middleware('can:setup.company.manage')->name('company.join-code.update');

        // Work Schedule & Holidays — the shifts employees follow (read by
        // Attendance) and the holiday calendar (read by Leave). Both addressed by
        // hashid; restore / force-delete take the hashid as a string.
        Route::get('schedule', [ScheduleSetupController::class, 'index'])->middleware('can:setup.schedule.view')->name('schedule.index');

        Route::patch('schedule/default', [WorkScheduleController::class, 'setDefault'])->middleware('can:setup.schedule.manage')->name('schedule.default');
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

        // Attendance Policies — how a day is judged (ADR 0038): grace, rounding,
        // lateness thresholds, overtime, breaks, night differential. Addressed by
        // hashid; restore / force-delete take it as a string. The worked example
        // is a read (it writes nothing) but posts the unsaved settings.
        Route::get('attendance-policies', [AttendancePolicyController::class, 'index'])->middleware('can:setup.attendance-policies.view')->name('attendance-policies.index');
        Route::post('attendance-policies/preview', [AttendancePolicyController::class, 'preview'])->middleware('can:setup.attendance-policies.view')->name('attendance-policies.preview');
        Route::post('attendance-policies', [AttendancePolicyController::class, 'store'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.store');
        Route::post('attendance-policies/{attendancePolicy}', [AttendancePolicyController::class, 'update'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.update');
        Route::patch('attendance-policies/{attendancePolicy}/default', [AttendancePolicyController::class, 'setDefault'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.default');
        Route::delete('attendance-policies/{attendancePolicy}', [AttendancePolicyController::class, 'destroy'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.destroy');
        Route::patch('attendance-policies/{attendancePolicy}/restore', [AttendancePolicyController::class, 'restore'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.restore');
        Route::delete('attendance-policies/{attendancePolicy}/force', [AttendancePolicyController::class, 'forceDelete'])->middleware('can:setup.attendance-policies.manage')->name('attendance-policies.force-delete');

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

        // Performance framework — the appraisal frameworks the Performance module
        // conducts reviews against (ADR 0028), the rating scales they measure on,
        // the criteria catalogue they draw from, and the review cycles they run
        // in. All addressed by hashid; restore / force take it as a string.
        Route::get('kpi', [KpiSetupController::class, 'index'])->middleware('can:setup.kpi.view')->name('kpi.index');

        Route::post('kpi/frameworks', [ReviewTemplateController::class, 'store'])->middleware('can:setup.kpi.manage')->name('kpi.frameworks.store');
        Route::post('kpi/frameworks/{reviewTemplate}', [ReviewTemplateController::class, 'update'])->middleware('can:setup.kpi.manage')->name('kpi.frameworks.update');
        Route::delete('kpi/frameworks/{reviewTemplate}', [ReviewTemplateController::class, 'destroy'])->middleware('can:setup.kpi.manage')->name('kpi.frameworks.destroy');
        Route::patch('kpi/frameworks/{reviewTemplate}/restore', [ReviewTemplateController::class, 'restore'])->middleware('can:setup.kpi.manage')->name('kpi.frameworks.restore');
        Route::delete('kpi/frameworks/{reviewTemplate}/force', [ReviewTemplateController::class, 'forceDelete'])->middleware('can:setup.kpi.manage')->name('kpi.frameworks.force-delete');

        Route::post('kpi/scales', [RatingScaleController::class, 'store'])->middleware('can:setup.kpi.manage')->name('kpi.scales.store');
        Route::post('kpi/scales/{ratingScale}', [RatingScaleController::class, 'update'])->middleware('can:setup.kpi.manage')->name('kpi.scales.update');
        Route::delete('kpi/scales/{ratingScale}', [RatingScaleController::class, 'destroy'])->middleware('can:setup.kpi.manage')->name('kpi.scales.destroy');
        Route::patch('kpi/scales/{ratingScale}/restore', [RatingScaleController::class, 'restore'])->middleware('can:setup.kpi.manage')->name('kpi.scales.restore');
        Route::delete('kpi/scales/{ratingScale}/force', [RatingScaleController::class, 'forceDelete'])->middleware('can:setup.kpi.manage')->name('kpi.scales.force-delete');

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

        // Onboarding Programs — reusable checklists (templates) that seed each
        // new hire's onboarding case. Configured here; instantiated by the
        // Onboarding module at hire time. Addressed by hashid.
        Route::get('onboarding', [OnboardingProgramController::class, 'index'])->middleware('can:onboarding.manage-programs')->name('onboarding.index');
        Route::post('onboarding', [OnboardingProgramController::class, 'store'])->middleware('can:onboarding.manage-programs')->name('onboarding.store');
        Route::post('onboarding/{program}', [OnboardingProgramController::class, 'update'])->middleware('can:onboarding.manage-programs')->name('onboarding.update');
        Route::delete('onboarding/{program}', [OnboardingProgramController::class, 'destroy'])->middleware('can:onboarding.manage-programs')->name('onboarding.destroy');

        // Recruitment Pipelines — the named, ordered hiring stages a job posting
        // picks from (ADR 0029). Every organisation that predates this feature
        // already has one ("Standard Hiring"); addressed by hashid.
        Route::get('recruitment-pipelines', [RecruitmentPipelineController::class, 'index'])->middleware('can:recruitment.configure-pipelines')->name('recruitment-pipelines.index');
        Route::post('recruitment-pipelines', [RecruitmentPipelineController::class, 'store'])->middleware('can:recruitment.configure-pipelines')->name('recruitment-pipelines.store');
        Route::post('recruitment-pipelines/{pipeline}', [RecruitmentPipelineController::class, 'update'])->middleware('can:recruitment.configure-pipelines')->name('recruitment-pipelines.update');
        Route::delete('recruitment-pipelines/{pipeline}', [RecruitmentPipelineController::class, 'destroy'])->middleware('can:recruitment.configure-pipelines')->name('recruitment-pipelines.destroy');

        // Offboarding Programs — reusable clearance templates that seed each
        // exit's checklist. Configured here; instantiated by the Offboarding
        // module when an exit is started. Addressed by hashid.
        Route::get('offboarding', [OffboardingProgramController::class, 'index'])->middleware('can:offboarding.manage-programs')->name('offboarding.index');
        Route::post('offboarding', [OffboardingProgramController::class, 'store'])->middleware('can:offboarding.manage-programs')->name('offboarding.store');
        Route::post('offboarding/{program}', [OffboardingProgramController::class, 'update'])->middleware('can:offboarding.manage-programs')->name('offboarding.update');
        Route::delete('offboarding/{program}', [OffboardingProgramController::class, 'destroy'])->middleware('can:offboarding.manage-programs')->name('offboarding.destroy');

        // Award Types — the catalogue of recognitions the Awards & Recognition
        // module gives out. Addressed by hashid; restore / force take it as a string.
        Route::get('award-types', [AwardTypeController::class, 'index'])->middleware('can:setup.award-types.view')->name('award-types.index');
        Route::post('award-types', [AwardTypeController::class, 'store'])->middleware('can:setup.award-types.manage')->name('award-types.store');
        Route::post('award-types/{awardType}', [AwardTypeController::class, 'update'])->middleware('can:setup.award-types.manage')->name('award-types.update');
        Route::delete('award-types/{awardType}', [AwardTypeController::class, 'destroy'])->middleware('can:setup.award-types.manage')->name('award-types.destroy');
        Route::patch('award-types/{awardType}/restore', [AwardTypeController::class, 'restore'])->middleware('can:setup.award-types.manage')->name('award-types.restore');
        Route::delete('award-types/{awardType}/force', [AwardTypeController::class, 'forceDelete'])->middleware('can:setup.award-types.manage')->name('award-types.force-delete');
    });
