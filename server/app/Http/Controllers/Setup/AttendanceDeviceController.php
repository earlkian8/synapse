<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\AttendanceDeviceRequest;
use App\Http\Requests\Setup\ImportDevicePunchesRequest;
use App\Http\Resources\AttendanceDeviceResource;
use App\Models\AttendanceDevice;
use App\Models\WorkLocation;
use App\Support\ActivityLogger;
use App\Support\Attendance\DeviceCsvImport;
use App\Support\Attendance\DevicePunchIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company Setup → Devices (ADR 0040): the kiosks and biometric scanners that send
 * punches. Registering one issues its key, shown once and never again — only
 * its hash is kept — so a lost key is replaced, not recovered. Deactivating a
 * device stops its key working without losing its punches; removing it archives
 * it. A scanner that cannot push is fed from its CSV export here.
 *
 * Addressed by hashid.
 */
class AttendanceDeviceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('setup/devices', [
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
        ]);
    }

    public function store(AttendanceDeviceRequest $request): RedirectResponse
    {
        $device = new AttendanceDevice([
            ...$request->safe()->only(['name', 'type', 'work_location_id']),
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $key = $device->issueKey();
        $device->save();

        ActivityLogger::log(
            event: 'created',
            description: "Registered {$device->type} \"{$device->name}\"",
            subject: $device,
            logName: 'company-setup',
            subjectLabel: $device->name,
        );

        return $this->withKey($device, $key, 'Device registered. Copy its key now — it will not be shown again.');
    }

    public function update(AttendanceDeviceRequest $request, AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $attendanceDevice->update([
            ...$request->safe()->only(['name', 'work_location_id']),
            ...($request->has('is_active') ? ['is_active' => $request->boolean('is_active')] : []),
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: $attendanceDevice->wasChanged('is_active')
                ? ($attendanceDevice->is_active ? 'Reactivated' : 'Deactivated')." device \"{$attendanceDevice->name}\""
                : "Updated device \"{$attendanceDevice->name}\"",
            subject: $attendanceDevice,
            logName: 'company-setup',
            subjectLabel: $attendanceDevice->name,
        );

        return $this->respond(match (true) {
            $attendanceDevice->wasChanged('is_active') && ! $attendanceDevice->is_active => 'Device deactivated. Its key no longer works; its punches are kept.',
            $attendanceDevice->wasChanged('is_active') => 'Device reactivated.',
            default => 'Device updated.',
        });
    }

    /**
     * Replace a device's key — when it was lost, or may have leaked. The old key
     * stops working at once.
     */
    public function rotateKey(AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $key = $attendanceDevice->issueKey();
        $attendanceDevice->save();

        ActivityLogger::log(
            event: 'updated',
            description: "Replaced the key of device \"{$attendanceDevice->name}\"",
            subject: $attendanceDevice,
            logName: 'company-setup',
            subjectLabel: $attendanceDevice->name,
        );

        return $this->withKey($attendanceDevice, $key, 'New key issued; the old one no longer works. Copy it now — it will not be shown again.');
    }

    public function destroy(AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $name = $attendanceDevice->name;
        $attendanceDevice->forceFill(['is_active' => false])->save();
        $attendanceDevice->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Removed device \"{$name}\"",
            logName: 'company-setup',
            subjectLabel: $name,
        );

        return $this->respond('Device removed. Its key no longer works; the punches it sent are kept.');
    }

    /**
     * Read a device's CSV export into punches, through the same ingestion a
     * push goes through, and remember how its columns map.
     */
    public function import(ImportDevicePunchesRequest $request, AttendanceDevice $attendanceDevice, DevicePunchIngestor $ingestor): RedirectResponse
    {
        $mapping = $request->mapping();
        $parsed = DeviceCsvImport::rows($request->file('file')->getRealPath(), $mapping, $attendanceDevice);

        if ($parsed['problems'] !== [] && $parsed['rows'] === []) {
            return $this->respond(implode(' ', $parsed['problems']), 'warning');
        }

        $attendanceDevice->forceFill(['csv_mapping' => $mapping])->save();

        $totals = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        $issues = [];

        foreach (array_chunk($parsed['rows'], DevicePunchIngestor::MAX_BATCH) as $batch) {
            $report = $ingestor->ingest($attendanceDevice, $batch, via: 'csv');

            foreach (['accepted', 'duplicates', 'rejected'] as $key) {
                $totals[$key] += $report[$key];
            }

            foreach ($report['results'] as $i => $result) {
                if (! in_array($result['status'], ['accepted', 'duplicate'], true)) {
                    $issues[] = ['line' => (int) $batch[$i]['line'], 'message' => (string) $result['message']];
                }
            }
        }

        // The rows that need attention, for the import dialog to list.
        Inertia::flash('device_import', [
            'device' => $attendanceDevice->hashid,
            ...$totals,
            'issues' => array_slice($issues, 0, 50),
            'problems' => $parsed['problems'],
        ]);

        return $this->respond(
            "{$totals['accepted']} ".str('punch')->plural($totals['accepted']).' recorded'
                .($totals['duplicates'] > 0 ? ", {$totals['duplicates']} already imported" : '')
                .($totals['rejected'] > 0 ? ", {$totals['rejected']} not recorded" : '').'.',
            $totals['rejected'] > 0 ? 'warning' : 'success',
        );
    }

    /**
     * Respond with the key flashed for this one page load only.
     */
    private function withKey(AttendanceDevice $device, string $key, string $message): RedirectResponse
    {
        Inertia::flash('device_key', [
            'device' => $device->hashid,
            'name' => $device->name,
            'type' => $device->type,
            'key' => $key,
        ]);

        return $this->respond($message);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
