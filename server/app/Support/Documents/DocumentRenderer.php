<?php

namespace App\Support\Documents;

use App\Models\Organization;
use App\Support\Files\Uploads;
use App\Support\Tenancy;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders the papers the system generates as PDFs under the company's
 * letterhead (ADR 0077): an exit's clearance form and certificate, and a
 * discipline case's notices (ADR 0105).
 *
 * Dompdf from Blade, with remote fetching and PHP off and its reach confined to
 * `resources/views/documents`: the company's logo is read from storage and
 * embedded, never fetched over the network.
 */
class DocumentRenderer
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [resource_path('views/documents')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, [...$data, 'company' => $this->letterhead()])->render(), 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Who the paper comes from: the company's name, address and contacts, its
     * logo (embedded) and who signs.
     *
     * @return array{name: string, legal_name: string, address: ?string, phone: ?string, email: ?string, logo: ?string, signatory_name: ?string, signatory_title: ?string}
     */
    public function letterhead(): array
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
     * The logo as a data URI, or null — a raster image under 2 MB on the
     * uploads disk. Anything else is left out rather than fetched.
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
