<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeAwardResource;
use App\Models\AwardType;
use App\Models\Employee;
use App\Models\EmployeeAward;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Awards & Recognition — the live recognition feed: every award given, with the
 * KPIs and the lookups needed to grant a new one. Award types are configured in
 * Company Setup; this module gives them out.
 */
class AwardController extends Controller
{
    public function index(Request $request): Response
    {
        $awards = EmployeeAward::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'awardType:id,name,color',
                'grantedBy:id,first_name,last_name',
            ])
            ->latestFirst()
            ->get();

        return Inertia::render('awards/index', [
            'awards' => EmployeeAwardResource::collection($awards)->resolve($request),
            'types' => $this->activeTypes(),
            'employees' => $this->activeEmployees(),
            'stats' => $this->stats($awards),
            'can' => ['manage' => $request->user()->can('awards.manage')],
        ]);
    }

    /**
     * Active award types for the give-recognition picker.
     *
     * @return list<array{id: int, name: string, color: string|null}>
     */
    private function activeTypes(): array
    {
        return AwardType::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (AwardType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'color' => $type->color,
            ])
            ->all();
    }

    /**
     * Active employees for the give-recognition picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function activeEmployees(): array
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
            ->all();
    }

    /**
     * Headline recognition metrics, derived from the award collection.
     *
     * @param  Collection<int, EmployeeAward>  $awards
     * @return array<string, int>
     */
    private function stats($awards): array
    {
        $startOfMonth = now()->startOfMonth();

        return [
            'total' => $awards->count(),
            'this_month' => $awards->filter(fn (EmployeeAward $a): bool => $a->awarded_on !== null && $a->awarded_on->gte($startOfMonth))->count(),
            'recognized' => $awards->pluck('employee_id')->unique()->count(),
            'types' => AwardType::query()->active()->count(),
        ];
    }
}
