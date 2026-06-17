<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Resources\AllowanceTypeResource;
use App\Http\Resources\DeductionTypeResource;
use App\Models\AllowanceType;
use App\Models\DeductionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Payroll Configuration: the allowance and deduction types the
 * payroll engine reads when building payslips (see App\Support\Payroll).
 */
class PayrollSetupController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/payroll', [
            'allowanceTypes' => AllowanceTypeResource::collection($this->allowances()->get())->resolve($request),
            'archivedAllowances' => AllowanceTypeResource::collection($this->allowances()->onlyTrashed()->get())->resolve($request),
            'deductionTypes' => DeductionTypeResource::collection($this->deductions()->get())->resolve($request),
            'archivedDeductions' => DeductionTypeResource::collection($this->deductions()->onlyTrashed()->get())->resolve($request),
            'can' => ['manage' => $request->user()->can('setup.payroll.manage')],
        ]);
    }

    /**
     * @return Builder<AllowanceType>
     */
    private function allowances(): Builder
    {
        return AllowanceType::query()->withCount('payslipEarnings')->orderBy('name');
    }

    /**
     * @return Builder<DeductionType>
     */
    private function deductions(): Builder
    {
        return DeductionType::query()
            ->withCount('payslipDeductions')
            ->orderByDesc('is_mandatory')
            ->orderBy('name');
    }
}
