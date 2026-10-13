<?php

namespace App\Support\Offboarding;

use App\Models\OffboardingCase;
use App\Models\Organization;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Files\Uploads;
use App\Support\OffboardingProvisioner;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The papers an exit produces (ADR 0077), generated as PDFs from the case: the
 * **clearance form** — the checklist as the sheet that used to be walked from
 * department to department, with a line for each to sign — and the
 * **Certificate of Employment**, which the Labor Code has an employer issue to a
 * leaver who asks (DOLE Labor Advisory 06-2020: within three days).
 *
 * The clearance form can be printed at any point of the exit; it says how far
 * the clearance has come on the day it was printed. The certificate needs the
 * exit completed, because it states the last day. Its number is given the
 * first time it is issued and kept, so every copy of one person's certificate
 * carries the same number — and that first issue is what the audit trail
 * records.
 *
 * Rendered by Dompdf from Blade, with remote fetching off: the company's logo
 * is read from storage and embedded, never fetched over the network.
 */
class OffboardingDocuments
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Whether the exit can have a Certificate of Employment yet.
     */
    public static function certificateReady(OffboardingCase $case): bool
    {
        return $case->status === 'completed' && $case->employee_id !== null;
    }

    /**
     * The clearance form as a PDF.
     */
    public function clearanceForm(OffboardingCase $case): string
    {
        $case->loadMissing([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id,date_hired,employment_type',
            'employee.department:id,name',
            'employee.position:id,title',
            'clearanceItems' => fn ($query) => $query->with('department:id,name', 'clearedBy:id,first_name,middle_name,last_name,suffix'),
        ]);

        $items = $case->clearanceItems;
        $cleared = $items->where('status', 'cleared')->count();

        return $this->render('documents.clearance-form', [
            'case' => $case,
            'employee' => $case->employee,
            'groups' => $items
                ->groupBy(fn ($item): string => $item->department?->name ?? 'Unassigned')
                ->sortKeysUsing(fn (string $a, string $b): int => ($a === 'Unassigned') <=> ($b === 'Unassigned') ?: strcmp($a, $b)),
            'total' => $items->count(),
            'cleared' => $cleared,
            'flagged' => $items->where('status', 'flagged')->count(),
            'status' => OffboardingProvisioner::clearanceStatus($items->count(), $cleared),
            'reference' => 'CLR-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * The Certificate of Employment as a PDF, numbering it the first time.
     *
     * @throws OffboardingException when the exit is not completed
     */
    public function certificate(OffboardingCase $case, ?User $by = null, string $channel = ''): string
    {
        if (! self::certificateReady($case)) {
            throw new OffboardingException('A Certificate of Employment is issued once the exit is completed: it states the last day.');
        }

        $case->loadMissing([
            'employee:id,user_id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id,date_hired,employment_type,employment_status',
            'employee.department:id,name',
            'employee.position:id,title',
        ]);

        $this->issue($case, $by, $channel);

        $issued = CarbonImmutable::parse(OrganizationClock::today());

        return $this->render('documents.certificate-of-employment', [
            'case' => $case,
            'employee' => $case->employee,
            'issued' => $issued,
            'issuedDay' => $issued->format('jS'),
            'from' => $case->employee?->date_hired,
            'to' => $case->last_working_day ?? $case->completed_at,
        ]);
    }

    /**
     * Answer a PDF to the browser — shown in it, with a filename to save it by.
     */
    public static function respond(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * "certificate-of-employment-juan-dela-cruz.pdf"
     */
    public static function filename(string $kind, OffboardingCase $case): string
    {
        $case->loadMissing('employee:id,first_name,middle_name,last_name,suffix');

        return $kind.'-'.Str::slug($case->employee?->full_name ?? 'employee').'.pdf';
    }

    /**
     * Give the certificate its number the first time, and record that it was
     * issued. Later copies keep the number and are not logged again.
     */
    private function issue(OffboardingCase $case, ?User $by, string $channel): void
    {
        if ($case->certificate_no !== null) {
            return;
        }

        $year = CarbonImmutable::parse(OrganizationClock::today())->year;

        $case->forceFill([
            'certificate_no' => 'COE-'.$year.'-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT),
            'certificate_issued_at' => now(),
        ])->save();

        $name = $case->employee?->full_name ?? 'an employee';

        ActivityLogger::log(
            event: 'created',
            description: "Issued a Certificate of Employment ({$case->certificate_no}) for {$name}"
                .($by !== null && $by->id === $case->employee?->user_id ? ' at their request' : '').$channel,
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $name,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [resource_path('views/documents')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, [...$data, 'company' => $this->letterhead()])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Who the paper comes from: the company's name, address and contacts, its
     * logo (embedded) and who signs.
     *
     * @return array{name: string, legal_name: string, address: ?string, phone: ?string, email: ?string, logo: ?string, signatory_name: ?string, signatory_title: ?string}
     */
    private function letterhead(): array
    {
        /** @var Organization|null $organization */
        $organization = $this->tenancy->organization();

        return [
            'name' => (string) ($organization?->name ?? config('app.name')),
            'legal_name' => (string) ($organization?->legal_name ?: $organization?->name ?: config('app.name')),
            'address' => $organization?->address,
            'phone' => $organization?->phone,
            'email' => $organization?->email,
            'logo' => $this->logo($organization),
            'signatory_name' => $organization?->document_signatory_name,
            'signatory_title' => $organization?->document_signatory_title,
        ];
    }

    /**
     * The logo as a data URI, or null — a raster image under 2 MB on the public
     * disk. Anything else is left out rather than fetched.
     */
    private function logo(?Organization $organization): ?string
    {
        $path = $organization?->logo;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Uploads::disk();

            if (! $disk->exists($path) || $disk->size($path) > 2 * 1024 * 1024) {
                return null;
            }

            $mime = $disk->mimeType($path);

            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }
}
