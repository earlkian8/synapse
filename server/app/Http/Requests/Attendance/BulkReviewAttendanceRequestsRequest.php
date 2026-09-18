<?php

namespace App\Http\Requests\Attendance;

use App\Support\Hashid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deciding several attendance requests with one note (ADR 0039). Requests are
 * named by hashid, as everywhere else in the UI; each is still decided — and
 * re-authorised — on its own by the approver.
 */
class BulkReviewAttendanceRequestsRequest extends FormRequest
{
    /** The most one bulk decision covers. */
    public const MAX = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'hashids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'hashids.*' => ['string', 'max:64'],
        ];
    }

    /**
     * The ids the hashids name, dropping any that do not decode.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_filter(array_map(
            fn (string $hashid): ?int => Hashid::decode($hashid),
            (array) $this->input('hashids', []),
        )));
    }
}
