<?php

namespace App\Support\Recruitment;

use App\Models\Interview;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may interview a candidate (ADR 0091).
 *
 * Interviewing is not HR's alone: a department head meets candidates for their
 * team, and a staff member is often asked to sit in. They should not need the
 * recruitment board to do it. So there are two ways to be an interviewer:
 *
 * - **`recruitment.interview`** — the interviews you are assigned, and only
 *   those: the candidate, their résumé, and your verdict and feedback.
 * - **`recruitment.schedule-interviews`** — HR, who books interviews and may
 *   record any of them.
 *
 * Somebody with neither is not offered as an interviewer and cannot be booked as
 * one — the screen, the request and the assistant all ask here, so an
 * interview never goes to somebody who could not open it.
 */
final class Interviewers
{
    /** The abilities that make somebody an interviewer. */
    public const ABILITIES = ['recruitment.interview', 'recruitment.schedule-interviews'];

    /**
     * Active members of this workspace who may be put on an interview, by name.
     *
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        // Roles are tenant-scoped, so these are this workspace's roles only.
        $roles = Role::query()
            ->where(fn (Builder $query) => $query
                ->where('name', Role::SUPER_ADMIN)
                ->orWhereHas('permissions', fn (Builder $permissions) => $permissions->whereIn('name', self::ABILITIES)))
            ->pluck('id');

        return User::query()
            ->where('is_active', true)
            ->inCurrentOrganization()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('roles.id', $roles))
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /**
     * The interviewer picker's choices.
     *
     * @return list<array{id: int, full_name: string}>
     */
    public static function options(): array
    {
        return self::query()
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->map(fn (User $user): array => ['id' => $user->id, 'full_name' => $user->full_name])
            ->all();
    }

    /**
     * Why this person cannot be put on an interview, or null when they can.
     */
    public static function whyNot(?User $user): ?string
    {
        if ($user === null) {
            return 'That interviewer is not a member of this workspace.';
        }

        return self::query()->whereKey($user->id)->exists()
            ? null
            : "{$user->full_name} can't interview candidates. Give one of their roles the “Interview the candidates you're assigned” permission first, under Roles & Permissions.";
    }

    /**
     * Whether this person may record the interview's verdict: HR always, an
     * interviewer for the interviews that are theirs.
     */
    public static function mayRecord(User $user, Interview $interview): bool
    {
        if ($user->can('recruitment.schedule-interviews')) {
            return true;
        }

        return $user->can('recruitment.interview') && $interview->interviewer_id === $user->id;
    }

    /**
     * Tell the interviewer they have an interview — unless they booked it
     * themselves.
     */
    public static function notify(Interview $interview, ?User $actor = null): void
    {
        $interviewer = $interview->interviewer;

        if ($interviewer === null || $interviewer->is($actor)) {
            return;
        }

        $interview->loadMissing('application.applicant', 'application.jobPosting');
        $when = $interview->scheduled_at->format('D, M j \a\t g:i A');

        Notifier::toUser(
            $interviewer,
            'You have an interview',
            "{$interview->application->applicant->full_name} for {$interview->application->jobPosting->title}, {$when}.",
            url: '/recruitment/interviews',
            category: 'recruitment',
            actor: $actor,
        );
    }
}
