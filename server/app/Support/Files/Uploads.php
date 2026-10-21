<?php

namespace App\Support\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where uploaded files live, and how they are linked to (ADR 0087).
 *
 * Every upload — a résumé, a contract, a clock-in selfie, a photo, a logo — is
 * stored on the private `uploads` disk. No bucket serves it. A link points at
 * this app, which streams the file only to somebody signed in to the workspace
 * the file belongs to and allowed to see the record it is on
 * ({@see UploadAccess}). A leaked link is useless to anybody else.
 *
 * The one exception is a company logo: the public careers page shows it to
 * people who are not signed in, so logos have a route of their own that serves
 * that folder and nothing else.
 */
final class Uploads
{
    public const DISK = 'uploads';

    public const LOGOS = 'organization-logos';

    /**
     * The folders uploads are stored in. Only these are ever served, so no
     * path can reach anything else on the disk.
     *
     * @var list<string>
     */
    public const FOLDERS = [
        'applicant-resumes',
        'applicant-documents',
        'employee-documents',
        'employee-certifications',
        'employee-photos',
        'profile-photos',
        'attendance/punches',
        'problem-reports',
        self::LOGOS,
    ];

    /**
     * Types a browser may show in the page, by extension. Anything else is
     * downloaded, so an uploaded HTML or SVG file can never run as one of this
     * app's pages.
     *
     * @var array<string, string>
     */
    private const INLINE_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * The link to a stored file, or null when there is none. A full URL
     * (seeded demo data) is returned as it is. The mobile app is given its own
     * route, which reads the token it already sends; the web app's reads the
     * session.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (str_starts_with($path, self::LOGOS.'/')) {
            return route('files.logo', ['file' => Str::after($path, self::LOGOS.'/')]);
        }

        $api = request()->is('api/*');

        return route($api ? 'api.files.show' : 'files.show', ['path' => $path]);
    }

    /**
     * Whether a path is one this app stores uploads at: inside a known folder,
     * with no way to climb out of it.
     */
    public static function servable(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        return self::folder($path) !== null;
    }

    /** The type a file may be shown inline as, or null when it must be downloaded. */
    public static function inlineType(string $path): ?string
    {
        return self::INLINE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /** The known folder a path is in, or null. */
    public static function folder(string $path): ?string
    {
        foreach (self::FOLDERS as $folder) {
            if (str_starts_with($path, $folder.'/') && strlen($path) > strlen($folder) + 1) {
                return $folder;
            }
        }

        return null;
    }
}
