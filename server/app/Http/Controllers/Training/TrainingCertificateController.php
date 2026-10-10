<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingCertificateResource;
use App\Models\EmployeeCertification;
use App\Models\Organization;
use App\Models\TrainingProgram;
use App\Support\Tenancy;
use App\Support\Training\TrainingCertificates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The certificates training has issued (ADR 0075): the register with each
 * one's standing — valid, expiring, expired or renewed — its CSV export, and a
 * certificate to print. The register needs `training.view`; a certificate can
 * be printed by whoever may see the register, and by its own holder.
 */
class TrainingCertificateController extends Controller
{
    use TrainingResponses;

    public function index(Request $request): Response
    {
        $certificates = TrainingCertificates::register()->latest('issued_date')->latest('id')->get();
        $listed = TrainingCertificateResource::listing($certificates, $request);

        $counts = collect($listed)->countBy('standing');

        return Inertia::render('training/certificates', [
            'certificates' => $listed,
            'stats' => [
                'valid' => (int) $counts->get('valid', 0),
                'expiring' => (int) $counts->get('expiring', 0),
                'expired' => (int) $counts->get('expired', 0),
                'renewed' => (int) $counts->get('renewed', 0),
            ],
            'expiring_days' => TrainingCertificates::EXPIRING_DAYS,
            // Programs still taking people, to renew a certificate in.
            'programs' => TrainingProgram::query()->recentFirst()->get(['id', 'name', 'start_date', 'end_date', 'issues_certificate'])
                ->filter(fn (TrainingProgram $p): bool => $p->status() !== 'completed')
                ->map(fn (TrainingProgram $p): array => ['hashid' => $p->hashid, 'name' => $p->name, 'status' => $p->status(), 'issues_certificate' => $p->issues_certificate])
                ->values()->all(),
            'standing' => in_array($request->query('standing'), TrainingCertificates::STANDINGS, true) ? $request->query('standing') : null,
            'can' => ['manage' => $request->user()->can('training.manage')],
            'nav' => $this->navCounts($request),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'training-certificates-'.now()->format('Y-m-d').'.csv';
        $certificates = TrainingCertificates::register()->latest('issued_date')->latest('id')->get();
        $rows = TrainingCertificateResource::listing($certificates, $request);

        return response()->stream(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Certificate No.', 'Employee', 'Employee No.', 'Department', 'Certificate', 'Issuer', 'Issued', 'Expires', 'Standing', 'Score']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['certificate_no'],
                    $row['employee']['full_name'] ?? null,
                    $row['employee']['employee_no'] ?? null,
                    $row['employee']['department'] ?? null,
                    $row['name'],
                    $row['issuer'],
                    $row['issued_date'],
                    $row['expiry_date'] ?? 'No expiry',
                    $row['standing'],
                    $row['score'] === null ? '' : number_format((float) $row['score'], 2),
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * One certificate, laid out to print — for HR, and for its holder.
     */
    public function show(Request $request, EmployeeCertification $certificate): Response
    {
        abort_unless($certificate->fromTraining(), 404);

        $user = $request->user();
        $own = $user->can('training.participate') && $certificate->employee_id === $user->employee()->value('id');

        abort_unless($own || $user->can('training.view'), 404);

        $certificate = TrainingCertificates::register()->whereKey($certificate->id)->firstOrFail();
        $theirs = TrainingCertificates::register()->where('employee_id', $certificate->employee_id)->get();
        $organization = app(Tenancy::class)->organization();

        return Inertia::render('training/certificate', [
            'certificate' => TrainingCertificateResource::listing(collect([$certificate]), $request, $theirs)[0],
            'organization' => [
                'name' => $organization instanceof Organization ? $organization->name : null,
                'logo' => $organization instanceof Organization ? $organization->logo_url : null,
            ],
            'completed_on' => $certificate->enrollment?->completed_at?->toDateString(),
            'back' => $own && $user->cannot('training.view') ? '/training/me?tab=certificates' : '/training/certificates',
        ]);
    }
}
