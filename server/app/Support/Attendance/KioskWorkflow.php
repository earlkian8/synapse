<?php

namespace App\Support\Attendance;

use App\Http\Requests\Attendance\IssueKioskPinsRequest;
use App\Http\Requests\Attendance\KioskRequest;
use App\Models\AttendanceKiosk;
use App\Models\Employee;
use App\Models\EmployeeKioskPin;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything HR changes about kiosks (ADR 0092): adding and editing one,
 * pairing codes, signing a device out, removing a kiosk, issuing PINs and
 * withdrawing them.
 *
 * Attendance → Kiosks and the assistant both come through here, so a kiosk is
 * written and recorded the same way whoever asked. Validation is
 * {@see KioskRequest} and {@see IssueKioskPinsRequest}, which run first.
 *
 * Two of these answer a secret — a pairing code, a PIN — which exists in the
 * clear only in what they return. The assistant never calls them: a secret in a
 * chat would sit in its history.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class KioskWorkflow
{
    /**
     * @param  array{name: string, work_location_id?: int|null}  $attributes
     */
    public function create(array $attributes, User $by, string $channel = ''): AttendanceKiosk
    {
        $kiosk = AttendanceKiosk::create([...$attributes, 'created_by' => $by->id]);

        $this->log('created', "Added the kiosk “{$kiosk->name}”{$channel}", $kiosk);

        return $kiosk;
    }

    /**
     * @param  array{name?: string, work_location_id?: int|null}  $attributes
     */
    public function update(AttendanceKiosk $kiosk, array $attributes, string $channel = ''): AttendanceKiosk
    {
        $kiosk->update($attributes);

        $this->log('updated', "Updated the kiosk “{$kiosk->name}”{$channel}", $kiosk, [
            'changes' => array_keys($kiosk->getChanges()),
        ]);

        return $kiosk;
    }

    /**
     * A pairing code for the kiosk, returned in the clear this once. The device
     * that types it becomes the kiosk; one that was before stops being it.
     */
    public function pairingCode(AttendanceKiosk $kiosk): string
    {
        $code = $kiosk->issuePairingCode();
        $kiosk->save();

        $this->log('updated', "Asked for a pairing code for the kiosk “{$kiosk->name}”", $kiosk);

        return $code;
    }

    /**
     * Sign the kiosk's device out: it stops clocking anybody in at once.
     */
    public function unpair(AttendanceKiosk $kiosk, string $channel = ''): void
    {
        $kiosk->unpair();
        $kiosk->save();

        $this->log('updated', "Signed the device out of the kiosk “{$kiosk->name}”{$channel}", $kiosk);
    }

    /**
     * Remove the kiosk: its device is signed out, and it is kept archived so the
     * punches made at it still name it.
     */
    public function remove(AttendanceKiosk $kiosk, string $channel = ''): void
    {
        DB::transaction(function () use ($kiosk): void {
            $kiosk->unpair();
            $kiosk->save();
            $kiosk->delete();
        });

        $this->log('deleted', "Removed the kiosk “{$kiosk->name}”{$channel}", $kiosk);
    }

    /**
     * Issue PINs, all or none, and return each in the clear this once — so a
     * PIN never exists that nobody was shown.
     *
     * @param  Collection<int, Employee>  $employees  With `kioskPin` loaded.
     * @return list<array{full_name: string, employee_no: string, pin: string, replaced: bool}>
     */
    public function issuePins(Collection $employees, User $by): array
    {
        return DB::transaction(fn (): array => $employees->map(function (Employee $employee) use ($by): array {
            $fresh = $employee->kioskPin === null;
            $pin = KioskPins::issue($employee, $by);

            $this->log($fresh ? 'created' : 'updated', $fresh ? 'Issued a kiosk PIN' : 'Issued a new kiosk PIN', $employee, label: $employee->full_name);

            return [
                'full_name' => $employee->full_name,
                'employee_no' => (string) $employee->employee_no,
                'pin' => $pin,
                'replaced' => ! $fresh,
            ];
        })->values()->all());
    }

    /**
     * Withdraw somebody's PIN: they can no longer clock in at any kiosk.
     */
    public function withdrawPin(EmployeeKioskPin $pin, string $channel = ''): void
    {
        $employee = $pin->employee;
        $pin->delete();

        $this->log('deleted', "Withdrew the kiosk PIN{$channel}", $employee, label: $employee?->full_name);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, AttendanceKiosk|Employee|null $subject, array $properties = [], ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            properties: $properties,
            logName: 'attendance',
            subjectLabel: $label ?? ($subject instanceof AttendanceKiosk ? $subject->name : null),
        );
    }
}
