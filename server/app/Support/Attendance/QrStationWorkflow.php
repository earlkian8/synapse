<?php

namespace App\Support\Attendance;

use App\Http\Requests\Attendance\QrStationRequest;
use App\Models\AttendanceQrStation;
use App\Models\User;
use App\Support\ActivityLogger;

/**
 * Everything HR changes about QR stations (ADR 0103): adding and editing one,
 * switching it off and on, resetting its codes, and removing it. Validation is
 * {@see QrStationRequest}, which runs first. Every change is written to the
 * activity log.
 */
class QrStationWorkflow
{
    /**
     * @param  array{name: string, work_location_id: int, rotate_seconds: int, scan_mode?: string, same_network?: bool, is_active?: bool}  $attributes
     */
    public function create(array $attributes, User $by): AttendanceQrStation
    {
        $station = AttendanceQrStation::create([...$attributes, 'created_by' => $by->id]);

        $this->log('created', "Added the QR station “{$station->name}”", $station);

        return $station;
    }

    /**
     * @param  array{name?: string, work_location_id?: int, rotate_seconds?: int, scan_mode?: string, same_network?: bool, is_active?: bool}  $attributes
     */
    public function update(AttendanceQrStation $station, array $attributes): AttendanceQrStation
    {
        $station->update($attributes);

        $changes = array_keys($station->getChanges());

        $this->log('updated', match (true) {
            $changes === ['is_active', 'updated_at'] || $changes === ['is_active'] => ($station->is_active ? 'Switched on' : 'Switched off')." the QR station “{$station->name}”",
            default => "Updated the QR station “{$station->name}”",
        }, $station, ['changes' => $changes]);

        return $station;
    }

    /**
     * A new secret: every code shown before stops working at once, and the
     * screen moves on to codes signed with the new one at its next ask. For a
     * screen photographed or left somewhere it should not have been.
     */
    public function resetCodes(AttendanceQrStation $station): void
    {
        $station->secret = AttendanceQrStation::newSecret();
        $station->save();

        $this->log('updated', "Reset the codes of the QR station “{$station->name}”", $station);
    }

    /**
     * Remove the station. It is kept archived, so the punches scanned at it
     * still name it; its codes stop working, since an archived station is
     * nobody's.
     */
    public function remove(AttendanceQrStation $station): void
    {
        $station->delete();

        $this->log('deleted', "Removed the QR station “{$station->name}”", $station);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, AttendanceQrStation $station, array $properties = []): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $station,
            properties: $properties,
            logName: 'attendance',
            subjectLabel: $station->name,
        );
    }
}
