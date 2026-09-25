<?php

namespace App\Queries\Setup;

use App\Http\Resources\AttendanceDeviceResource;
use App\Models\AttendanceDevice;
use App\Models\WorkLocation;
use Illuminate\Http\Request;

/**
 * Company Setup → Devices (ADR 0040): the kiosks and biometric scanners that
 * send punches, and where they send them to.
 */
class DevicesScreen implements SetupScreen
{
    public function toArray(Request $request): array
    {
        return [
            'devices' => AttendanceDeviceResource::collection(
                AttendanceDevice::query()
                    ->with('location:id,name')
                    ->withCount('punches')
                    ->orderByDesc('is_active')
                    ->orderBy('name')
                    ->get(),
            )->resolve($request),
            'locations' => WorkLocation::query()->active()->orderBy('name')->get(['id', 'name']),
            // Where a device sends to, for the setup instructions.
            'endpoints' => [
                'punches' => route('api.devices.punches'),
                'kiosk' => route('kiosk'),
            ],
            'can' => ['manage' => $request->user()->can('setup.devices.manage')],
        ];
    }
}
