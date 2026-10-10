<?php

namespace App\Http\Resources;

use App\Models\EmployeeCertification;
use App\Support\Training\TrainingCertificates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A certificate earned through training (ADR 0075), with its standing today —
 * valid, expiring, expired or renewed. Whether it was renewed is worked out
 * across the person's certificates, so a list goes through {@see self::listing()}.
 *
 * @mixin EmployeeCertification
 */
class TrainingCertificateResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly bool $renewed = false)
    {
        parent::__construct($resource);
    }

    /**
     * Certificates as plain arrays, each knowing whether a later one of the same
     * name, held by the same person, has renewed it.
     *
     * @param  Collection<int, EmployeeCertification>  $certificates
     * @param  Collection<int, EmployeeCertification>|null  $among  The certificates to judge renewal across (defaults to the list).
     * @return list<array<string, mixed>>
     */
    public static function listing(Collection $certificates, Request $request, ?Collection $among = null): array
    {
        $renewed = TrainingCertificates::renewedIds($among ?? $certificates);

        return $certificates
            ->map(fn (EmployeeCertification $c): array => (new self($c, isset($renewed[$c->id])))->resolve($request))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $program = $this->enrollment?->program;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'certificate_no' => $this->certificate_no,
            'issued_date' => $this->issued_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'standing' => TrainingCertificates::standing($this->resource, $this->renewed),
            'score' => $this->enrollment?->score === null ? null : (float) $this->enrollment->score,
            'program' => $program ? [
                'hashid' => $program->hashid,
                'name' => $program->name,
                'archived' => $program->trashed(),
            ] : null,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'department' => $this->employee->relationLoaded('department') ? $this->employee->department?->name : null,
                'position' => $this->employee->relationLoaded('position') ? $this->employee->position?->title : null,
            ] : null),
        ];
    }
}
