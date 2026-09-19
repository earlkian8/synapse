<?php

namespace App\Support\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Support\ActivityLogger;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Takes punches from a kiosk or biometric device (ADR 0040) — pushed over the
 * API, or read from a CSV export — and records them through the punch engine.
 *
 * **Record, then flag — never reject.** A scanner on a wall reports what
 * happened; it cannot be told "you're already clocked in". So a device's punch
 * goes through {@see AttendanceClock::capture()} with `record_only`: it is
 * written as it came, even out of order or twice, and the evaluator flags the
 * day (`device_sequence_anomaly`) for sign-off. What is refused is only what
 * cannot be written at all: a row that names nobody the company employs, a row
 * that is not a punch, a day inside a locked period.
 *
 *  - **Idempotent** on the device and its own id for the punch: devices resend,
 *    and a resend returns `duplicate`.
 *  - **Who** — `employee_ref` is matched to an employee number, then to a device
 *    enrolment id, exactly and ignoring case.
 *  - **When** — an instant with an offset is taken as it is; a bare date and
 *    time is read on the organisation's clock, which is how scanners keep time.
 *  - **What** — `type` is optional; an untyped punch is inferred from the day
 *    (in, out, in …).
 *
 * Every row gets an outcome, so a device (or the person importing its file)
 * learns exactly which rows need attention. One activity entry is written per
 * batch, not per punch.
 */
class DevicePunchIngestor
{
    /** The most rows one batch may carry. */
    public const MAX_BATCH = 500;

    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Each `{external_id, employee_ref, punched_at, type?}`.
     * @param  string|null  $sentAt  The device's clock when it sent the batch, to measure its skew.
     * @return array{accepted: int, duplicates: int, rejected: int, results: list<array{external_id: ?string, status: string, message: ?string, work_date: ?string}>}
     */
    public function ingest(AttendanceDevice $device, array $rows, ?string $sentAt = null, string $via = 'push'): array
    {
        $employees = $this->employees($rows);
        $results = [];
        $counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];

        foreach (array_values($rows) as $row) {
            $result = $this->one($device, (array) $row, $employees, $sentAt);
            $results[] = $result;
            $counts[$result['status'] === 'accepted' || $result['status'] === 'duplicate' ? $result['status'] : 'rejected']++;
        }

        if ($results !== []) {
            ActivityLogger::log(
                event: 'created',
                description: ($via === 'csv' ? "Imported punches for {$device->name}: " : "{$device->name} sent punches: ")
                    ."{$counts['accepted']} recorded, {$counts['duplicate']} already received, {$counts['rejected']} not recorded",
                subject: $device,
                properties: ['via' => $via, ...$counts],
                logName: 'attendance',
                subjectLabel: $device->name,
            );
        }

        return [
            'accepted' => $counts['accepted'],
            'duplicates' => $counts['duplicate'],
            'rejected' => $counts['rejected'],
            'results' => $results,
        ];
    }

    /**
     * One row's outcome.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Employee>  $employees
     * @return array{external_id: ?string, status: string, message: ?string, work_date: ?string}
     */
    private function one(AttendanceDevice $device, array $row, Collection $employees, ?string $sentAt): array
    {
        $externalId = trim((string) ($row['external_id'] ?? ''));
        $reference = mb_strtolower(trim((string) ($row['employee_ref'] ?? '')));
        $type = filled($row['type'] ?? null) ? (string) $row['type'] : null;
        $outcome = fn (string $status, ?string $message = null, ?string $date = null): array => [
            'external_id' => $externalId !== '' ? $externalId : null,
            'status' => $status,
            'message' => $message,
            'work_date' => $date,
        ];

        if ($externalId === '' || mb_strlen($externalId) > 100) {
            return $outcome('invalid', 'Every punch needs its own id (external_id), up to 100 characters.');
        }

        if ($type !== null && ! in_array($type, AttendancePunch::TYPES, true)) {
            return $outcome('invalid', 'The type must be one of '.implode(', ', AttendancePunch::TYPES).', or left out.');
        }

        $at = $this->instant($row['punched_at'] ?? null);

        if ($at === null) {
            return $outcome('invalid', 'The time (punched_at) could not be read.');
        }

        $employee = $employees->get($reference);

        if ($reference === '' || $employee === null) {
            return $outcome('unknown_employee', 'No employee has the number or enrolment id "'.trim((string) ($row['employee_ref'] ?? '')).'".');
        }

        try {
            $captured = $this->clock->capture($employee, $type, [
                'source' => $device->punchSource(),
                'punched_at' => $at,
                'attendance_device_id' => $device->id,
                'work_location_id' => $device->work_location_id,
                'external_id' => $externalId,
                'sent_at' => $sentAt,
                'record_only' => true,
            ]);
        } catch (AttendanceException $e) {
            return $outcome('refused', $e->getMessage());
        }

        return $outcome(
            $captured->duplicate ? 'duplicate' : 'accepted',
            null,
            $captured->record->work_date->toDateString(),
        );
    }

    /**
     * Everybody the batch names, in two queries, keyed by the lower-cased
     * reference that found them — an employee number first, then an enrolment id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<string, Employee>
     */
    private function employees(array $rows): Collection
    {
        $references = collect($rows)
            ->map(fn ($row): string => mb_strtolower(trim((string) (((array) $row)['employee_ref'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return collect();
        }

        $byEnrolment = Employee::query()
            ->whereNotNull('device_enrollment_id')
            ->whereIn(DB::raw('lower(device_enrollment_id)'), $references)
            ->get()
            ->keyBy(fn (Employee $employee): string => mb_strtolower($employee->device_enrollment_id));

        $byNumber = Employee::query()
            ->whereIn(DB::raw('lower(employee_no)'), $references)
            ->get()
            ->keyBy(fn (Employee $employee): string => mb_strtolower($employee->employee_no));

        // Base collections: an Eloquent merge would re-key by primary key.
        return $byEnrolment->toBase()->merge($byNumber->toBase());
    }

    /**
     * A device's timestamp as an instant: with an offset, as given; without, as
     * a reading of the organisation's clock.
     */
    private function instant(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;

            return ($hasZone
                ? CarbonImmutable::parse($value)
                : CarbonImmutable::parse($value, OrganizationClock::timezone()))->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
