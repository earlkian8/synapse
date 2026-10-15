<?php

namespace App\Http\Middleware;

use App\Models\AttendanceKiosk;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Knows a kiosk by the secret its pairing left in its cookie (ADR 0092), and
 * binds the kiosk's organisation as the tenant — nobody signs in on a kiosk, so
 * the device is what says which company it is.
 *
 * - **The secret is in a cookie nothing on the page can read.** It is
 *   encrypted (the app's cookie encryption), HttpOnly, and SameSite=Strict, so
 *   script on the page cannot lift it and another site cannot post with it.
 * - **A browser somebody is signed in on is not a kiosk.** Its pages carry the
 *   signed-in person's name, permissions and workspaces; whoever stood at it
 *   could walk into their account. The kiosk refuses until they sign out.
 * - **A device that is no longer a kiosk is told so** — it was signed out or
 *   removed, or paired again elsewhere — and its cookie is cleared.
 *
 * Every kiosk route answers JSON; the page itself ({@see KioskDeviceController::show()})
 * reads the cookie without this, to say which screen to show.
 */
class AuthenticateKiosk
{
    /** The cookie a paired device keeps its secret in. */
    public const COOKIE = 'synapse_kiosk';

    /** The request attribute the kiosk is put under. */
    public const ATTRIBUTE = 'kiosk';

    /** Don't write `last_seen_at` more often than this. */
    private const SEEN_EVERY_SECONDS = 60;

    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return response()->json([
                'message' => 'Someone is signed in to Synapse in this browser. Sign out to use it as a kiosk.',
                'reason' => 'signed_in',
            ], 409);
        }

        $kiosk = self::kioskFor($request);

        if ($kiosk === null) {
            return response()->json([
                'message' => 'This device is no longer set up as a kiosk. Ask HR for a pairing code.',
                'reason' => 'unpaired',
            ], 401)->withCookie(Cookie::forget(self::COOKIE));
        }

        $this->tenancy->set($kiosk->organization);

        if ($kiosk->last_seen_at === null || $kiosk->last_seen_at->lt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            // A query, not a save: being seen is not an edit anybody made.
            AttendanceKiosk::query()->whereKey($kiosk->id)->update(['last_seen_at' => now()]);
        }

        $request->attributes->set(self::ATTRIBUTE, $kiosk);

        $response = $next($request);

        // What a kiosk is answered can name a person; nothing should keep it.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The kiosk this device is, from its cookie, with its organisation and
     * site — or null.
     */
    public static function kioskFor(Request $request): ?AttendanceKiosk
    {
        $kiosk = AttendanceKiosk::findByDeviceToken($request->cookie(self::COOKIE));

        return $kiosk?->load(['organization', 'location']);
    }

    /**
     * The cookie that makes a device a kiosk: long-lived, since a tablet on a
     * wall should not need pairing again every month — HR ends it instead.
     */
    public static function cookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            self::COOKIE,
            $token,
            minutes: 60 * 24 * 365 * 5,
            path: '/',
            secure: (bool) (config('session.secure') ?? request()->secure()),
            httpOnly: true,
            sameSite: 'strict',
        );
    }
}
