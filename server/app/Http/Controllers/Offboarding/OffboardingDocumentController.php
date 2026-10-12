<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Models\OffboardingCase;
use App\Support\Offboarding\OffboardingDocuments;
use App\Support\Offboarding\OffboardingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An exit's papers as PDFs (ADR 0077): the clearance form, printable at any
 * point of the exit, and the Certificate of Employment, once it is completed.
 * Shown in the browser to print or save. Gated by `offboarding.view`.
 */
class OffboardingDocumentController extends Controller
{
    use OffboardingResponses;

    public function __construct(private readonly OffboardingDocuments $documents) {}

    public function clearance(OffboardingCase $case): Response
    {
        return OffboardingDocuments::respond(
            $this->documents->clearanceForm($case),
            OffboardingDocuments::filename('clearance-form', $case),
        );
    }

    public function certificate(Request $request, OffboardingCase $case): Response|RedirectResponse
    {
        try {
            $pdf = $this->documents->certificate($case, $request->user());
        } catch (OffboardingException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        return OffboardingDocuments::respond($pdf, OffboardingDocuments::filename('certificate-of-employment', $case));
    }
}
