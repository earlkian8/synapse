<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\OffboardingCase;
use App\Models\User;
use App\Support\Hashid;
use App\Support\Offboarding\OffboardingDocuments;
use App\Support\Offboarding\OffboardingException;
use App\Support\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Certificate of Employment opened from the app's short-lived link (ADR 0077).
 * There is no session or token here: the signature in the address is the key —
 * minted for this exit and this person, minutes ago — so it is checked by the
 * route's `signed` middleware, and the exit's own company is bound to render it.
 */
class CertificateDownloadController extends Controller
{
    public function __invoke(string $case, int $user, Tenancy $tenancy, OffboardingDocuments $documents): Response
    {
        $id = Hashid::decode($case);
        $exit = $id === null ? null : OffboardingCase::query()->with('organization')->find($id);
        $holder = User::query()->find($user);

        abort_if($exit === null || $exit->organization === null || $holder === null, 404);

        return $tenancy->runFor($exit->organization, function () use ($exit, $holder, $documents): Response {
            // Still theirs: the link was minted for the exit's own employee.
            abort_unless($exit->employee()->value('user_id') === $holder->id, 404);

            try {
                $pdf = $documents->certificate($exit, $holder, ' from the mobile app');
            } catch (OffboardingException) {
                abort(404);
            }

            return OffboardingDocuments::respond($pdf, OffboardingDocuments::filename('certificate-of-employment', $exit));
        });
    }
}
