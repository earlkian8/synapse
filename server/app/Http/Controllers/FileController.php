<?php

namespace App\Http\Controllers;

use App\Support\Files\FileStream;
use App\Support\Files\UploadAccess;
use App\Support\Files\Uploads;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an uploaded file from the private disk (ADR 0087). The bucket serves
 * nothing itself; this is the only way a file leaves it.
 *
 * Anything that may not be opened — another workspace's file, a record the
 * asker may not see, a path outside the upload folders, a file that is gone —
 * answers 404, so a path cannot be probed for whether it exists.
 */
class FileController extends Controller
{
    /** A file on a record, for somebody signed in (web session or app token). */
    public function show(Request $request, UploadAccess $access, string $path): StreamedResponse
    {
        abort_unless($access->allows($request->user(), $path), 404);

        // Kept by the browser but checked with us on every use, so a copy left
        // behind after signing out cannot be opened again; an unchanged file is
        // answered with a cheap 304.
        return FileStream::response($request, $path, 'private, no-cache');
    }

    /**
     * A company logo, for anybody: the public careers page and the invitation
     * page show it signed out. This route reaches the logo folder only.
     */
    public function logo(Request $request, string $file): StreamedResponse
    {
        return FileStream::response($request, Uploads::LOGOS.'/'.$file, 'public, max-age=3600');
    }
}
