<?php

namespace App\Http\Controllers\Offboarding;

use App\Models\Employee;
use App\Models\ResignationRequest;
use App\Support\Offboarding\OffboardingException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * How the offboarding screens answer (ADR 0076): a workflow's refusal is a
 * warning toast in its own words; the person acting is resolved to their
 * employee record; and every page carries the count its section switcher
 * shows on Resignations.
 */
trait OffboardingResponses
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
        } catch (OffboardingException $e) {
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
     * The counts the section switcher shows: resignations waiting for whoever
     * may decide them.
     *
     * @return array{resignations: int}
     */
    protected function navCounts(Request $request): array
    {
        return [
            'resignations' => $request->user()->can('offboarding.manage')
                ? ResignationRequest::query()->pending()->whereHas('employee')->count()
                : 0,
        ];
    }
}
