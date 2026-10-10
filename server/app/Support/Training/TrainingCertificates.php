<?php

namespace App\Support\Training;

use App\Models\EmployeeCertification;
use App\Models\Organization;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Certificates earned by completing training (ADR 0075).
 *
 * A certificate is an ordinary {@see EmployeeCertification} — the same register
 * the employee profile and the assistant already read — tied to the enrollment
 * that earned it. It follows the enrollment: marking someone completed on a
 * program that issues certificates issues one, and undoing the completion (or
 * removing the person) withdraws it. Turning certificates on for a program
 * issues them to everyone who had already completed it.
 *
 * A certificate is dated on the day the person completed (on the organisation's
 * clock) and expires the program's validity in months later; with no validity it
 * never expires. Changing the program afterwards does not touch certificates
 * already issued — they record what was earned.
 *
 * Its standing is derived, never stored: **expired** after its expiry date,
 * **expiring** within {@see self::EXPIRING_DAYS} days of it, **valid** otherwise,
 * and **renewed** when the same person later earned a certificate of the same
 * name — a lapsed certificate that has been renewed is no longer a gap.
 */
class TrainingCertificates
{
    /** How far ahead an expiry counts as coming up — and when its holder is told. */
    public const EXPIRING_DAYS = 60;

    /** The standings a certificate can be in. */
    public const STANDINGS = ['valid', 'expiring', 'expired', 'renewed'];

    /**
     * Bring an enrollment's certificate in line with it: issue one when it is
     * completed on a program that issues them, withdraw it when it is not
     * completed. A certificate already issued stays as it is.
     */
    public function sync(TrainingEnrollment $enrollment, string $channel = ''): void
    {
        $enrollment->loadMissing(['program', 'employee', 'certificate']);
        $existing = $enrollment->certificate;

        if ($enrollment->status !== 'completed') {
            if ($existing !== null) {
                $this->withdraw($enrollment, $existing, $channel);
            }

            return;
        }

        if ($existing === null && $enrollment->program?->issues_certificate) {
            $this->issue($enrollment, $channel);
        }
    }

    /**
     * Issue a certificate to everyone who has completed the program and has
     * none — when a program starts issuing them after people finished.
     *
     * @return int How many were issued.
     */
    public function backfill(TrainingProgram $program, string $channel = ''): int
    {
        if (! $program->issues_certificate) {
            return 0;
        }

        $owed = $program->enrollments()
            ->where('status', 'completed')
            ->whereDoesntHave('certificate')
            ->with(['employee', 'program'])
            ->get();

        foreach ($owed as $enrollment) {
            $this->issue($enrollment, $channel, quietly: true);
        }

        if ($owed->isNotEmpty()) {
            ActivityLogger::log(
                event: 'created',
                description: "Issued {$owed->count()} ".Str::plural('certificate', $owed->count())." for {$program->name} to people who had completed it{$channel}",
                subject: $program,
                logName: 'training',
                subjectLabel: $program->name,
            );
        }

        return $owed->count();
    }

    /**
     * A certificate's standing today.
     *
     * @param  bool  $renewed  Whether the holder has since earned one of the same name.
     */
    public static function standing(EmployeeCertification $certificate, bool $renewed = false): string
    {
        if ($renewed) {
            return 'renewed';
        }

        if ($certificate->expiry_date === null) {
            return 'valid';
        }

        $today = OrganizationClock::today();
        $expiry = $certificate->expiry_date->toDateString();

        return match (true) {
            $expiry < $today => 'expired',
            $expiry <= CarbonImmutable::parse($today)->addDays(self::EXPIRING_DAYS)->toDateString() => 'expiring',
            default => 'valid',
        };
    }

    /**
     * The ids of training certificates that a later one of the same name, held
     * by the same person, has renewed.
     *
     * @param  Collection<int, EmployeeCertification>  $certificates
     * @return array<int, true>
     */
    public static function renewedIds(Collection $certificates): array
    {
        $renewed = [];

        $certificates
            ->groupBy(fn (EmployeeCertification $c): string => $c->employee_id.'|'.Str::lower(trim($c->name)))
            ->each(function (Collection $group) use (&$renewed): void {
                $latest = $group->sortByDesc(fn (EmployeeCertification $c): string => ($c->issued_date?->toDateString() ?? '').'|'.str_pad((string) $c->id, 10, '0', STR_PAD_LEFT))->first();

                foreach ($group as $certificate) {
                    if ($certificate->id !== $latest->id) {
                        $renewed[$certificate->id] = true;
                    }
                }
            });

        return $renewed;
    }

    /**
     * Tell each holder whose certificate expires within
     * {@see self::EXPIRING_DAYS} days, once, and tell whoever runs training how
     * many are coming up. A certificate that has been renewed is left alone.
     *
     * @return int How many holders' certificates were flagged.
     */
    public function sendDueReminders(): int
    {
        $today = OrganizationClock::today();
        $horizon = CarbonImmutable::parse($today)->addDays(self::EXPIRING_DAYS)->toDateString();

        $due = EmployeeCertification::query()
            ->fromTraining()
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $horizon)
            ->with('employee.user')
            ->get();

        if ($due->isEmpty()) {
            return 0;
        }

        // The person's other training certificates, to see which are renewed.
        $theirs = EmployeeCertification::query()
            ->fromTraining()
            ->whereIn('employee_id', $due->pluck('employee_id')->unique())
            ->get();
        $renewed = self::renewedIds($theirs);

        $flagged = 0;

        foreach ($due as $certificate) {
            $certificate->forceFill(['expiry_notified_at' => now()])->saveQuietly();

            if (isset($renewed[$certificate->id])) {
                continue;
            }

            $flagged++;
            $user = $certificate->employee?->user;

            if ($user !== null && $user->is_active) {
                Notifier::toUser(
                    $user,
                    'Your certificate expires soon',
                    "“{$certificate->name}” expires on {$certificate->expiry_date->format('M j, Y')}. Ask HR about renewing it.",
                    '/training/me?tab=certificates',
                    'warning',
                    'training',
                );
            }
        }

        if ($flagged > 0) {
            Notifier::toPermission(
                'training.manage',
                $flagged === 1 ? 'A training certificate expires soon' : "{$flagged} training certificates expire soon",
                ($flagged === 1 ? 'One certificate expires' : "{$flagged} certificates expire").' within '.self::EXPIRING_DAYS.' days. Enroll the holders in a program to renew them.',
                '/training/certificates?standing=expiring',
                'warning',
                'training',
            );
        }

        return $flagged;
    }

    /**
     * The training certificates of this workspace, with what each needs: the
     * holder, the program it came from (archived ones included).
     *
     * @return Builder<EmployeeCertification>
     */
    public static function register(): Builder
    {
        return EmployeeCertification::query()
            ->fromTraining()
            ->whereHas('employee')
            ->with([
                'employee:id,user_id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
                'enrollment:id,training_program_id,employee_id,score,completed_at',
                'enrollment.program' => fn ($query) => $query->withTrashed()->select('id', 'name', 'provider'),
            ]);
    }

    private function issue(TrainingEnrollment $enrollment, string $channel, bool $quietly = false): EmployeeCertification
    {
        $program = $enrollment->program;
        $issued = OrganizationClock::local($enrollment->completed_at ?? now())->startOfDay();
        $months = $program?->certificate_validity_months;

        $certificate = EmployeeCertification::create([
            'employee_id' => $enrollment->employee_id,
            'training_enrollment_id' => $enrollment->id,
            'name' => $program?->name ?? 'Training',
            'issuer' => $program?->provider ?: $this->organizationName(),
            'issued_date' => $issued->toDateString(),
            'expiry_date' => $months ? $issued->addMonthsNoOverflow($months)->toDateString() : null,
        ]);

        $certificate->forceFill([
            'certificate_no' => sprintf('TC-%s-%05d', $issued->format('Y'), $certificate->id),
        ])->saveQuietly();

        $enrollment->setRelation('certificate', $certificate);

        if (! $quietly) {
            ActivityLogger::log(
                event: 'created',
                description: "Issued a certificate for {$program?->name} to {$enrollment->employee?->full_name}{$channel}",
                subject: $program,
                logName: 'training',
                subjectLabel: $program?->name,
            );
        }

        return $certificate;
    }

    private function withdraw(TrainingEnrollment $enrollment, EmployeeCertification $certificate, string $channel): void
    {
        $certificate->delete();
        $enrollment->setRelation('certificate', null);

        ActivityLogger::log(
            event: 'deleted',
            description: "Withdrew {$enrollment->employee?->full_name}'s certificate for {$enrollment->program?->name}{$channel}",
            subject: $enrollment->program,
            logName: 'training',
            subjectLabel: $enrollment->program?->name,
        );
    }

    private function organizationName(): ?string
    {
        $organization = app(Tenancy::class)->organization();

        return $organization instanceof Organization ? $organization->name : null;
    }
}
