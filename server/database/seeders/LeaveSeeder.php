<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Support\LeaveCalculator;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    /**
     * The leave types every organisation starts with (Philippine-style defaults).
     *
     * @var list<array{name: string, code: string, color: string, default_days: float, is_paid: bool, requires_approval: bool}>
     */
    private const TYPES = [
        ['name' => 'Vacation Leave', 'code' => 'VL', 'color' => '#0ABFBF', 'default_days' => 15, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Sick Leave', 'code' => 'SL', 'color' => '#EF4444', 'default_days' => 15, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Emergency Leave', 'code' => 'EL', 'color' => '#F59E0B', 'default_days' => 5, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Bereavement Leave', 'code' => 'BL', 'color' => '#6B7280', 'default_days' => 5, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Maternity Leave', 'code' => 'ML', 'color' => '#EC4899', 'default_days' => 105, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Paternity Leave', 'code' => 'PL', 'color' => '#6366F1', 'default_days' => 7, 'is_paid' => true, 'requires_approval' => true],
        ['name' => 'Unpaid Leave', 'code' => 'LWOP', 'color' => '#8B5CF6', 'default_days' => 0, 'is_paid' => false, 'requires_approval' => true],
    ];

    /**
     * Seed the default leave types plus a little demo activity (balances and a few
     * requests across statuses). Idempotent: only seeds when no types exist yet.
     */
    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        if (LeaveType::count() > 0) {
            return;
        }

        // 1. Leave types.
        $types = collect(self::TYPES)->mapWithKeys(fn (array $type) => [
            $type['code'] => LeaveType::create([
                'name' => $type['name'],
                'code' => $type['code'],
                'color' => $type['color'],
                'default_days' => $type['default_days'],
                'is_paid' => $type['is_paid'],
                'allow_half_day' => true,
                'requires_approval' => $type['requires_approval'],
                'is_active' => true,
            ]),
        ]);

        // 2. A bit of demo activity for a handful of employees.
        $year = (int) now()->year;
        $reviewer = User::where('email', 'dev@nexo.com')->first();
        $employees = Employee::query()->inRandomOrder()->limit(6)->get();

        foreach ($employees as $i => $employee) {
            // Explicit entitlements for the core paid types.
            foreach (['VL', 'SL', 'EL'] as $code) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $types[$code]->id, 'year' => $year],
                    ['entitled_days' => $types[$code]->default_days],
                );
            }

            $this->seedRequest($employee, $types['VL'], 'approved', -20, 3, $reviewer);

            if ($i % 2 === 0) {
                $this->seedRequest($employee, $types['SL'], 'pending', 2, 1, null);
            }

            if ($i % 3 === 0) {
                $this->seedRequest($employee, $types['EL'], 'rejected', -5, 1, $reviewer);
            }
        }
    }

    /**
     * Create one leave request with server-consistent day counts.
     */
    private function seedRequest(
        Employee $employee,
        LeaveType $type,
        string $status,
        int $startOffsetDays,
        int $spanDays,
        ?User $reviewer,
    ): void {
        $start = now()->copy()->addDays($startOffsetDays);

        // Keep demo leave on weekdays so it charges whole working days.
        // (Reassign — the app's dates are immutable, so addDay() returns a copy.)
        while ($start->isWeekend()) {
            $start = $start->addDay();
        }

        $end = (clone $start)->addDays($spanDays - 1);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => LeaveCalculator::chargeableDays($start, $end, false),
            'is_half_day' => false,
            'reason' => $status === 'rejected' ? 'Overlaps a critical deadline.' : null,
            'status' => $status,
            'filed_by' => $reviewer?->id,
            'reviewed_by' => $status === 'pending' ? null : $reviewer?->id,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
    }
}
