<?php

namespace App\Support\Files;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\EmployeeDocument;
use App\Models\ProblemReportAttachment;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who may open a stored file (ADR 0087): somebody signed in to the workspace the
 * file belongs to, who may see the record it is on.
 *
 * A file is found by looking up the record that holds its path, through the
 * models' own tenant scope, so a file from another workspace is simply not
 * found. Then the same permission the screen showing that record asks for is
 * asked here:
 *
 * - a résumé — `recruitment.view`, or the asker interviews that candidate
 *   (ADR 0091); an applicant document — `recruitment.view`;
 * - an employee document or certification — `employees.view`, or it is the
 *   asker's own;
 * - a clock-in photo — `attendance.view`, or it is the asker's own punch;
 * - an employee photo — anybody in the workspace (the directory, the roster
 *   and recognition all show it);
 * - a profile photo — the person themselves, or anybody who shares the
 *   workspace with them;
 * - a screenshot on a problem report (ADR 0096) — the reporter alone, in
 *   whichever workspace: the report is theirs, not the company's. Admins open
 *   it through the portal's own route.
 */
final class UploadAccess
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function allows(User $user, string $path): bool
    {
        if (! Uploads::servable($path)) {
            return false;
        }

        $folder = Uploads::folder($path);

        if ($folder === 'profile-photos' && $user->profile_photo === $path) {
            return true;
        }

        if ($folder === 'problem-reports') {
            return ProblemReportAttachment::query()
                ->where('path', $path)
                ->whereHas('report', fn (Builder $q) => $q->where('user_id', $user->id))
                ->exists();
        }

        // The tenant scope switches itself off when no workspace is bound, so
        // "no workspace" is the one state in which every lookup below would see
        // the whole instance. Refuse rather than look.
        if (! $this->tenancy->check()) {
            return false;
        }

        return match ($folder) {
            'applicant-resumes' => $this->resume($user, $path),
            'applicant-documents' => $user->can('recruitment.view')
                && $this->exists(ApplicantDocument::query(), 'file', $path),
            'employee-documents' => $this->employeeFile(EmployeeDocument::query(), $user, $path),
            'employee-certifications' => $this->employeeFile(EmployeeCertification::query(), $user, $path),
            'attendance/punches' => $this->punch($user, $path),
            'employee-photos' => $this->exists(Employee::query(), 'photo', $path),
            'profile-photos' => User::query()
                ->where('profile_photo', $path)
                ->whereHas('memberships', fn (Builder $q) => $q->whereKey($this->tenancy->id()))
                ->exists(),
            default => false,
        };
    }

    /**
     * A résumé opens for recruiters, and for an interviewer of that candidate —
     * the CV is what they interview from. Their other documents (IDs,
     * transcripts) stay with recruiters.
     */
    private function resume(User $user, string $path): bool
    {
        $query = Applicant::query()->where('resume', $path);

        if ($user->can('recruitment.view')) {
            return $query->exists();
        }

        return $user->can('recruitment.interview')
            && $query->whereHas('applications.interviews', fn (Builder $q) => $q->where('interviewer_id', $user->id))->exists();
    }

    /**
     * @param  Builder<EmployeeDocument>|Builder<EmployeeCertification>  $query
     */
    private function employeeFile(Builder $query, User $user, string $path): bool
    {
        $query = $this->withTrashed($query)->where('file', $path);

        if ($user->can('employees.view')) {
            return $query->exists();
        }

        return $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    private function punch(User $user, string $path): bool
    {
        $query = AttendancePunch::query()->where('photo', $path);

        if ($user->can('attendance.view')) {
            return $query->exists();
        }

        return $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', $user->id))->exists();
    }

    /**
     * @param  Builder<*>  $query
     */
    private function exists(Builder $query, string $column, string $path): bool
    {
        return $this->withTrashed($query)->where($column, $path)->exists();
    }

    /**
     * Archived records keep their files and still appear in history.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function withTrashed(Builder $query): Builder
    {
        return in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)
            ? $query->withTrashed()
            : $query;
    }
}
