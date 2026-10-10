<?php

namespace App\Http\Controllers\Training;

use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Support\Training\TrainingException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * How the training screens answer (ADRs 0074, 0075): a refusal that belongs to
 * a form field comes back as that field's error, so the form stays open with
 * what was typed; any other as a warning toast in its own words. The person
 * acting is resolved to their employee record, and every page carries the
 * count its section switcher shows on Programs.
 */
trait TrainingResponses
{
    /**
     * Run a workflow call, then toast `$success` (a string, or a closure given
     * the call's result).
     *
     * @param  string|Closure(mixed): string  $success
     */
    protected function attempt(Closure $call, string|Closure $success): RedirectResponse
    {
        try {
            $result = $call();
        } catch (TrainingException $e) {
            if ($e->field !== null) {
                throw ValidationException::withMessages([$e->field => $e->getMessage()]);
            }

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
     * The counts the section switcher shows: requests to join that wait for
     * whoever may decide them.
     *
     * @return array{requests: int}
     */
    protected function navCounts(Request $request): array
    {
        return [
            'requests' => $request->user()->can('training.manage')
                ? TrainingEnrollment::query()->pending()->whereHas('program')->count()
                : 0,
        ];
    }
}
