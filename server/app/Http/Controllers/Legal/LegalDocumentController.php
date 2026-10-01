<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Support\Legal\LegalDocuments;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Privacy Policy and the Terms of Service (ADR 0063). Public: people read
 * them before they have an account — on the sign-up form, and applicants on a
 * careers page — so they sit outside every guard.
 */
class LegalDocumentController extends Controller
{
    public function privacy(): Response
    {
        return $this->show('privacy');
    }

    public function terms(): Response
    {
        return $this->show('terms');
    }

    private function show(string $key): Response
    {
        return Inertia::render('legal/show', [
            'document' => LegalDocuments::find($key),
        ]);
    }
}
