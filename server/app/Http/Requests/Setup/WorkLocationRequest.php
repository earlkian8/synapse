<?php

namespace App\Http\Requests\Setup;

use App\Models\WorkLocation;
use App\Support\Attendance\FenceGeometry;
use App\Support\OrganizationClock;
use App\Support\TenantRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a work location (ADR 0040): its name and address, the fence
 * — a point and a radius, or a shape of 3 to 50 corners that does not cross
 * itself (ADR 0079) — the clock the site keeps, and the schedule and attendance
 * policy the people based there default to.
 *
 * A shape's centre and the circle that holds it are worked out here, never
 * taken from the form ({@see locationAttributes()}).
 */
class WorkLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor($this->route('workLocation'));
    }

    /**
     * The rules for creating a site (null) or editing this one. Static so a
     * caller with no route — the assistant — holds a site to exactly these.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(?WorkLocation $location): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                TenantRule::unique('work_locations', 'name')->whereNull('deleted_at')->ignore($location?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'fence_type' => ['sometimes', Rule::in(WorkLocation::FENCE_TYPES)],
            'latitude' => ['required_unless:fence_type,polygon', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_unless:fence_type,polygon', 'nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required_unless:fence_type,polygon', 'nullable', 'integer', 'min:'.WorkLocation::MIN_RADIUS_METERS, 'max:'.WorkLocation::MAX_RADIUS_METERS],
            'polygon' => ['required_if:fence_type,polygon', 'nullable', 'array', 'min:3', 'max:'.WorkLocation::MAX_POLYGON_POINTS, self::shapeRule()],
            'polygon.*' => ['array', 'size:2'],
            'polygon.*.0' => ['required', 'numeric', 'between:-90,90'],
            'polygon.*.1' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['nullable', 'string', Rule::in(OrganizationClock::identifiers())],
            'default_work_schedule_id' => ['nullable', 'integer', TenantRule::exists('work_schedules', 'id')->whereNull('deleted_at')],
            'attendance_policy_id' => ['nullable', 'integer', TenantRule::exists('attendance_policies', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a location with that name.',
            'latitude.required' => 'Place the site on the map.',
            'longitude.required' => 'Place the site on the map.',
            'radius_meters.min' => 'Draw the fence at least :min m wide — a phone’s position is rarely surer than that.',
            'radius_meters.max' => 'A fence can be at most :max m across.',
            'polygon.required_if' => 'Click the map at each corner of the site — three at least.',
            'polygon.min' => 'A shape needs at least :min corners.',
            'polygon.max' => 'A shape can have at most :max corners.',
            'timezone.in' => 'Pick a time zone from the list.',
        ];
    }

    /**
     * A shape's edges may not cross, and it must fit in the widest circle a
     * fence may be.
     */
    private static function shapeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || count($value) < 3) {
                return;
            }

            foreach ($value as $corner) {
                if (! is_array($corner) || count($corner) !== 2 || ! is_numeric($corner[0] ?? null) || ! is_numeric($corner[1] ?? null)) {
                    return;
                }
            }

            $shape = array_values(array_map(fn (array $corner): array => [(float) $corner[0], (float) $corner[1]], $value));

            if (FenceGeometry::selfIntersects($shape)) {
                $fail('The shape’s edges cross. Move a corner so the outline goes round once.');

                return;
            }

            if (FenceGeometry::areaSquareMeters($shape) < WorkLocation::MIN_RADIUS_METERS ** 2) {
                $fail('Draw the shape bigger — a phone’s position is rarely surer than '.WorkLocation::MIN_RADIUS_METERS.' m.');

                return;
            }

            [$lat, $lng] = FenceGeometry::centroid($shape);

            if (FenceGeometry::reachMeters($lat, $lng, $shape) > WorkLocation::MAX_RADIUS_METERS) {
                $fail('A fence can reach at most '.WorkLocation::MAX_RADIUS_METERS.' m from its centre.');
            }
        };
    }

    /**
     * The location's own columns, ready to write.
     *
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        return self::fenceAttributes([
            ...$this->safe()->only(['name', 'address', 'latitude', 'longitude', 'radius_meters', 'fence_type', 'polygon', 'timezone', 'default_work_schedule_id', 'attendance_policy_id']),
            ...($this->has('is_active') ? ['is_active' => $this->boolean('is_active')] : []),
        ]);
    }

    /**
     * Settle the fence columns from what was validated: a shape gets its centre
     * and the circle that holds it worked out; a circle drops any shape.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function fenceAttributes(array $attributes): array
    {
        if (($attributes['fence_type'] ?? null) !== 'polygon') {
            return array_key_exists('fence_type', $attributes)
                ? [...$attributes, 'polygon' => null]
                : $attributes;
        }

        $shape = array_values(array_map(
            fn (array $corner): array => [round((float) $corner[0], 7), round((float) $corner[1], 7)],
            $attributes['polygon'] ?? [],
        ));
        [$lat, $lng] = FenceGeometry::centroid($shape);
        $reach = (int) ceil(FenceGeometry::reachMeters($lat, $lng, $shape));

        return [
            ...$attributes,
            'polygon' => $shape,
            'latitude' => round($lat, 7),
            'longitude' => round($lng, 7),
            'radius_meters' => max(WorkLocation::MIN_RADIUS_METERS, min(WorkLocation::MAX_RADIUS_METERS, $reach)),
        ];
    }
}
