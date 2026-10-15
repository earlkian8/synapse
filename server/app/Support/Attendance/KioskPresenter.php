<?php

namespace App\Support\Attendance;

use App\Models\AttendanceKiosk;
use App\Models\AttendancePunch;
use App\Models\EmployeeKioskPin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What a kiosk's screen is told (ADR 0092). Deliberately little: the kiosk
 * stands where anybody can read it, so it says who is signed in by first name
 * and initials, today's punches and what they may punch next — never a photo, a
 * department, a pay figure or anybody else.
 */
final class KioskPresenter
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * The kiosk itself: its header, its clock, and the server's time, so a
     * tablet whose own clock is off still shows the right one.
     *
     * @return array<string, mixed>
     */
    public function kiosk(AttendanceKiosk $kiosk): array
    {
        $organization = $kiosk->organization;

        return [
            'name' => $kiosk->name,
            'site' => $kiosk->location?->name,
            'organization' => [
                'name' => $organization?->name,
                'logo_url' => $organization?->logo_url,
                'initials' => $organization?->initials(),
            ],
            'timezone' => $kiosk->clockZone(),
            'pin_length' => EmployeeKioskPin::LENGTH,
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * Somebody signed in at the kiosk, and their day as it stands.
     *
     * @return array<string, mixed>
     */
    public function person(EmployeeKioskPin $pin, string $ticket): array
    {
        $employee = $pin->employee;

        return [
            'ticket' => $ticket,
            'expires_in' => KioskTickets::SECONDS,
            'must_change_pin' => $pin->must_change,
            'person' => [
                'first_name' => $employee->first_name,
                'full_name' => $employee->full_name,
                'initials' => $employee->initials(),
            ],
            'day' => $this->day($pin),
        ];
    }

    /**
     * The day a punch now would land on — the shift they are on, a night shift
     * after midnight included — and what its policy says of kiosks.
     *
     * @return array<string, mixed>
     */
    public function day(EmployeeKioskPin $pin): array
    {
        $employee = $pin->employee;
        $record = $this->clock->currentRecord($employee);
        $policy = $this->clock->policyOf($record);
        $shift = $this->clock->shiftFor($employee, $record->work_date->toDateString());

        return [
            'work_date' => $record->work_date->toDateString(),
            'shift' => $this->shiftWords($shift),
            'next_expected' => $this->clock->nextExpected($record),
            'allowed' => $this->clock->allowed($record),
            'punches' => $record->punches
                ->sortBy('punched_at')
                ->map(fn (AttendancePunch $punch): array => [
                    'type' => $punch->type,
                    'at' => $punch->punched_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            // Said up front, so nobody is turned away only after they punch.
            'kiosk_allowed' => in_array('kiosk', $policy->allowedSources, true),
            'photo_required' => $policy->selfieRequired,
        ];
    }

    /**
     * The day's shift as the kiosk says it — "Shift 8:00 AM – 5:00 PM", "8h to
     * work today" — or null on a day off.
     */
    private function shiftWords(ResolvedShift $shift): ?string
    {
        if (! $shift->isWorkingDay) {
            return null;
        }

        if ($shift->type === 'hours_only' || $shift->segments === []) {
            return $shift->label().' to work today';
        }

        $clock = fn (string $time): string => CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A');

        return 'Shift '.implode(' and ', array_map(
            fn (array $segment): string => $clock($segment['start']).' – '.$clock($segment['end']),
            $shift->segments,
        ));
    }

    /**
     * "Chrome on Android", "Safari on iPad" — enough for HR to tell which device
     * a kiosk is, without keeping the whole user agent.
     */
    public static function deviceLabel(?string $agent): ?string
    {
        $agent = (string) $agent;

        if ($agent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $system = match (true) {
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Windows') => 'Windows',
            // iPadOS asks for desktop pages as a Mac.
            str_contains($agent, 'Macintosh') => 'Mac or iPad',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return Str::limit($system !== null ? "{$browser} on {$system}" : $browser, 117);
    }
}
