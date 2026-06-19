<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Per-employee profile records the operational module seeders don't cover —
 * documents, certifications and promotion history — so the Employee detail
 * drawer's Documents, Certifications and Promotions tabs have real data.
 *
 * Tenant-aware and idempotent per employee: each section is only seeded for an
 * employee who has none yet, so it safely tops up a growing roster on re-run.
 */
class EmployeeProfileSeeder extends Seeder
{
    /** The documents every employee gets (title, type, file placeholder). */
    private const CORE_DOCUMENTS = [
        ['title' => 'Employment Contract', 'type' => 'contract', 'file' => 'employee-documents/sample/employment-contract.pdf'],
        ['title' => 'Government IDs (SSS, TIN, PhilHealth, Pag-IBIG)', 'type' => 'govt_id', 'file' => 'employee-documents/sample/government-ids.pdf'],
    ];

    /** Optional extra documents, dealt to a subset of the roster for variety. */
    private const EXTRA_DOCUMENTS = [
        ['title' => 'Résumé / Curriculum Vitae', 'type' => 'cv', 'file' => 'employee-documents/sample/resume.pdf'],
        ['title' => 'NBI Clearance', 'type' => 'other', 'file' => 'employee-documents/sample/nbi-clearance.pdf'],
        ['title' => 'Certificate of Previous Employment', 'type' => 'other', 'file' => 'employee-documents/sample/coe.pdf'],
    ];

    /**
     * The certification pool. `issued`/`expiry` are month offsets from now
     * (negative = past). A null expiry never lapses; a negative one reads as
     * expired so the "Expired" badge has something to show.
     *
     * @var list<array{name: string, issuer: string, issued: int, expiry: ?int}>
     */
    private const CERTIFICATIONS = [
        ['name' => 'TESDA National Certificate II', 'issuer' => 'TESDA', 'issued' => -36, 'expiry' => 24],
        ['name' => 'Project Management Professional (PMP)', 'issuer' => 'PMI', 'issued' => -24, 'expiry' => 12],
        ['name' => 'AWS Certified Solutions Architect – Associate', 'issuer' => 'Amazon Web Services', 'issued' => -12, 'expiry' => 24],
        ['name' => 'Lean Six Sigma Green Belt', 'issuer' => 'ASQ', 'issued' => -48, 'expiry' => null],
        ['name' => 'Certified Public Accountant (CPA)', 'issuer' => 'PRC', 'issued' => -72, 'expiry' => null],
        ['name' => 'Occupational Safety & Health (OSH) Training', 'issuer' => 'DOLE', 'issued' => -26, 'expiry' => -2],
        ['name' => 'Certified Human Resource Associate (CHRA)', 'issuer' => 'PMAP', 'issued' => -30, 'expiry' => 12],
    ];

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

        $uploaderId = User::query()->orderBy('id')->value('id');
        $positions = Position::query()->orderBy('id')->get();
        $employees = Employee::query()->orderBy('id')->get()->values();

        foreach ($employees as $i => $employee) {
            $this->seedDocuments($employee, $i, $uploaderId);
            $this->seedCertifications($employee, $i);
            $this->seedPromotion($employee, $i, $positions, $uploaderId);
        }
    }

    /** Two core documents for everyone, plus one or two extras for variety. */
    private function seedDocuments(Employee $employee, int $i, ?int $uploaderId): void
    {
        if ($employee->documents()->exists()) {
            return;
        }

        $documents = self::CORE_DOCUMENTS;
        $documents[] = self::EXTRA_DOCUMENTS[$i % count(self::EXTRA_DOCUMENTS)];

        if ($i % 2 === 0) {
            $documents[] = self::EXTRA_DOCUMENTS[($i + 1) % count(self::EXTRA_DOCUMENTS)];
        }

        foreach ($documents as $document) {
            $employee->documents()->create([
                'title' => $document['title'],
                'type' => $document['type'],
                'file' => $document['file'],
                'uploaded_by' => $uploaderId,
            ]);
        }
    }

    /** A certification for ~70% of staff; every third of those holds a second. */
    private function seedCertifications(Employee $employee, int $i): void
    {
        if ($employee->certifications()->exists() || $i % 10 >= 7) {
            return;
        }

        $first = self::CERTIFICATIONS[$i % count(self::CERTIFICATIONS)];
        $this->makeCertification($employee, $first);

        if ($i % 3 === 0) {
            $second = self::CERTIFICATIONS[($i + 3) % count(self::CERTIFICATIONS)];

            if ($second['name'] !== $first['name']) {
                $this->makeCertification($employee, $second);
            }
        }
    }

    /**
     * @param  array{name: string, issuer: string, issued: int, expiry: ?int}  $cert
     */
    private function makeCertification(Employee $employee, array $cert): void
    {
        $employee->certifications()->create([
            'name' => $cert['name'],
            'issuer' => $cert['issuer'],
            'issued_date' => now()->addMonths($cert['issued'])->toDateString(),
            'expiry_date' => $cert['expiry'] === null ? null : now()->addMonths($cert['expiry'])->toDateString(),
            'file' => null,
        ]);
    }

    /**
     * One promotion on record for each tenured employee (hired 18+ months ago) —
     * from a lower role in the same department (any other role as a fallback)
     * into the employee's current position, with a salary bump. The most recent
     * hires keep an empty history, so the "no promotions" state is testable too.
     *
     * @param  Collection<int, Position>  $positions
     */
    private function seedPromotion(Employee $employee, int $i, Collection $positions, ?int $approverId): void
    {
        if ($employee->promotions()->exists()) {
            return;
        }

        $tenured = $employee->date_hired !== null && $employee->date_hired->lte(now()->subMonths(18));

        if (! $tenured || $employee->position_id === null) {
            return;
        }

        $toSalary = $employee->basic_salary !== null ? (float) $employee->basic_salary : 45000.0;

        $fromPosition = $positions
            ->where('department_id', $employee->department_id)
            ->firstWhere('id', '!=', $employee->position_id)
            ?? $positions->firstWhere('id', '!=', $employee->position_id);

        $employee->promotions()->create([
            'from_position_id' => $fromPosition?->id,
            'to_position_id' => $employee->position_id,
            'from_salary' => round($toSalary * 0.82, 2),
            'to_salary' => $toSalary,
            'effective_date' => now()->subMonths(8 + ($i % 12))->toDateString(),
            'reason' => 'Promoted in recognition of sustained strong performance and expanded responsibilities.',
            'approved_by' => $approverId,
        ]);
    }
}
