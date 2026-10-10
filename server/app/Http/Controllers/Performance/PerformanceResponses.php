<?php

namespace App\Http\Controllers\Performance;

use App\Models\AppraisalReview;
use App\Models\Employee;
use App\Models\User;
use App\Support\Performance\AppraisalException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * How the performance screens answer (ADRs 0072, 0073): a refusal from a
 * workflow comes back as a warning toast in its own words, the person acting is
 * resolved to their employee record, and every page carries the count its
 * section switcher shows on Reviews.
 */
trait PerformanceResponses
{
    /**
     * Run a workflow call, then toast `$success` (a string, or a closure given
     * the call's result). A refusal comes back in its own words.
     *
     * @param  string|Closure(mixed): string  $success
     */
    protected function attempt(Closure $call, string|Closure $success): RedirectResponse
    {
        try {
            $result = $call();
        } catch (AppraisalException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        return $this->toast(is_string($success) ? $success : $success($result));
    }

    protected function toast(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    /**
     * The signed-in person's employee record here, if they have one.
     */
    protected function ownEmployee(Request $request): ?Employee
    {
        return $request->user()->employee()->first();
    }

    /**
     * The counts the section switcher shows: reviews waiting for this person.
     *
     * @return array{reviews: int}
     */
    protected function navCounts(User $user): array
    {
        $employeeId = $user->employee()->value('id');

        return [
            'reviews' => $employeeId === null ? 0 : AppraisalReview::query()
                ->byReviewer((int) $employeeId)
                ->pending()
                ->whereHas('evaluation', fn ($q) => $q->where('status', 'draft'))
                ->count(),
        ];
    }
}
