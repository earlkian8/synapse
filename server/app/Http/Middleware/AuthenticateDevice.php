<?php

namespace App\Http\Middleware;

use App\Models\AttendanceDevice;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a kiosk or biometric device by its key (ADR 0040) — sent as
 * `Authorization: Bearer <key>` or `X-Device-Key` — and binds the organisation
 * it belongs to as the tenant, so every query behind it is that company's. No
 * user is signed in: the device is the principal, and no user permission
 * applies.
 *
 * A kiosk-only route passes `kiosk`, so a scanner's key cannot drive the
 * interactive kiosk (which reveals who an employee number belongs to).
 */
class AuthenticateDevice
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $device = AttendanceDevice::findByKey($request->bearerToken() ?? $request->header('X-Device-Key'));

        if ($device === null) {
            return response()->json(['message' => 'This device key is not recognised, or the device has been deactivated.'], 401);
        }

        if ($type !== null && $device->type !== $type) {
            return response()->json(['message' => 'This device is not registered as a '.$type.'.'], 403);
        }

        $organization = $device->organization()->first();

        if ($organization === null) {
            return response()->json(['message' => 'This device key is not recognised, or the device has been deactivated.'], 401);
        }

        $this->tenancy->set($organization);
        $request->attributes->set('attendance_device', $device);

        // Seen, whatever it asked for — the Devices screen's health column.
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
