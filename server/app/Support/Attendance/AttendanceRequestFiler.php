<?php

namespace App\Support\Attendance;

use App\Http\Requests\Attendance\StoreAttendanceRequestRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Files an attendance request (ADR 0039) — the one place the web, the mobile
 * API and the assistant all go through, so a request filed from any of them is
 * shaped, refused, logged and announced the same way.
 *
 * The data is already valid ({@see StoreAttendanceRequestRequest}).
 * What this adds is what validation cannot know: a range touching a locked
 * period is refused (it could never be approved), and a second pending request
 * of the same type for the same day is refused too, so the reviewer never has
 * two answers to give to one question.
 */
class AttendanceRequestFiler
{
    public function __construct(private readonly PeriodLock $lock) {}

    /**
     * @param  array<string, mixed>  $data  Validated input.
     *
     * @throws AttendanceException
     */
    public function file(Employee $employee, array $data, User $by, ?UploadedFile $attachment = null, string $via = 'web'): AttendanceRequest
    {
        $type = (string) $data['type'];
        $start = (string) $data['start_date'];
        $end = in_array($type, AttendanceRequest::SINGLE_DAY_TYPES, true) ? $start : ((string) ($data['end_date'] ?? '') ?: $start);

        $this->lock->assertRangeOpen($start, $end);

        $duplicate = AttendanceRequest::query()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('status', 'pending')
            ->overlapping($start, $end)
            ->exists();

        if ($duplicate) {
            throw new AttendanceRequestException('There is already a pending '.self::noun($type).' request for '.($start === $end ? 'that day' : 'those days').'. Cancel it first, or wait for a decision.');
        }

        $record = $start === $end
            ? AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('work_date', $start)->first()
            : null;

        $request = AttendanceRequest::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'attendance_record_id' => $record?->id,
            'payload' => $this->payload($type, $data, $start),
            'reason' => trim((string) $data['reason']),
            'attachment' => $attachment?->store('attendance/requests', 'public'),
            'status' => 'pending',
            'requested_by' => $by->id,
        ]);

        $request->setRelation('employee', $employee);

        ActivityLogger::log(
            event: 'created',
            description: 'Filed '.self::label($request)." for {$employee->full_name}".($via === 'assistant' ? ' via assistant' : ''),
            subject: $request,
            properties: ['type' => $type, 'from' => $start, 'to' => $end, 'source' => $via],
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        Notifier::toPermission(
            'attendance.requests.review',
            'Attendance request to review',
            "{$employee->full_name} asked for ".self::label($request).'.',
            url: '/attendance?tab=requests',
            category: 'attendance',
            actor: $by,
            // Nobody is asked to decide their own request.
            except: array_values(array_filter([$employee->user_id])),
        );

        return $request;
    }

    /**
     * The type-specific ask, keeping only what the type uses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(string $type, array $data, string $start): array
    {
        $time = fn (string $key): ?string => filled($data[$key] ?? null) ? substr((string) $data[$key], 0, 5) : null;

        return match ($type) {
            'correction' => array_combine(
                AttendanceRequest::CORRECTION_FIELDS,
                array_map($time, AttendanceRequest::CORRECTION_FIELDS),
            ),
            'overtime' => [
                'minutes' => (int) $data['minutes'],
                // Filed before the day it is for.
                'pre_approval' => $start > OrganizationClock::today(),
            ],
            default => [
                'start_time' => $time('start_time'),
                'end_time' => $time('end_time'),
                'location' => filled($data['location'] ?? null) ? trim((string) $data['location']) : null,
            ],
        };
    }

    /**
     * A request the way a notification or a log line says it: "a correction for
     * Sep 14", "2h of overtime on Sep 14", "official business on Sep 14 – 16".
     */
    public static function label(AttendanceRequest $request): string
    {
        $start = CarbonImmutable::parse($request->start_date->toDateString());
        $end = CarbonImmutable::parse($request->end_date->toDateString());
        $range = $start->equalTo($end) ? $start->format('M j') : $start->format('M j').' – '.$end->format('M j');

        return match ($request->type) {
            'correction' => "a correction for {$range}",
            'overtime' => self::minutes($request->requestedMinutes())." of overtime on {$range}",
            'official_business' => "official business on {$range}",
            'remote_work' => "remote work on {$range}",
            default => "a request for {$range}",
        };
    }

    /**
     * The type as a noun: "correction", "overtime", "official business".
     */
    public static function noun(string $type): string
    {
        return str_replace('_', ' ', $type);
    }

    /**
     * "2h", "1h 30m", "45m".
     */
    public static function minutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest}m",
            $rest === 0 => "{$hours}h",
            default => "{$hours}h {$rest}m",
        };
    }
}
