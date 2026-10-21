<?php

namespace App\Support\Files;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A stored upload as a response (ADR 0087): the one way a file leaves the
 * private disk, for the ERP's file routes and the admin portal's alike. The
 * caller decides who may have it; this only streams it.
 *
 * Anything that is not there — a path outside the upload folders, a file that
 * is gone — answers 404, so a path cannot be probed for whether it exists.
 */
final class FileStream
{
    public static function response(Request $request, string $path, string $cache): StreamedResponse
    {
        abort_unless(Uploads::servable($path), 404);

        $disk = Uploads::disk();

        // One metadata read, which is also the existence check. The type comes
        // from the stored name (the app names every upload by its content), not
        // from whatever type the uploader's browser claimed.
        try {
            $modified = $disk->lastModified($path);
        } catch (Throwable) {
            abort(404);
        }

        $type = Uploads::inlineType($path);

        $response = new StreamedResponse(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $type ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($type !== null ? 'inline' : 'attachment', basename($path)),
            'Cache-Control' => $cache,
        ]);

        $response->setLastModified(Carbon::createFromTimestamp($modified));
        $response->isNotModified($request);

        return $response;
    }
}
