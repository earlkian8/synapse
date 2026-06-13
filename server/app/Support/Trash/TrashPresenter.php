<?php

namespace App\Support\Trash;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Flattens a heterogeneous trashed model into the uniform shape the Trash Bin
 * table renders, together with the acting user's per-item capabilities.
 */
class TrashPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(string $type, Model $model, User $actor): array
    {
        $definition = TrashRegistry::definition($type);
        [$title, $subtitle, $avatar] = self::describe($type, $model);

        /** @var CarbonInterface|null $deletedAt */
        $deletedAt = $model->deleted_at;

        return [
            'type' => $type,
            'type_label' => $definition['label'] ?? $type,
            'id' => $model->getKey(),
            'title' => $title,
            'subtitle' => $subtitle,
            'avatar' => $avatar,
            'deleted_at' => $deletedAt?->toIso8601String(),
            'deleted_human' => $deletedAt?->diffForHumans(),
            'can_restore' => $actor->can($definition['restore']),
            'can_force_delete' => $actor->can($definition['forceDelete']),
        ];
    }

    /**
     * Resolve [title, subtitle, avatar] for a given trashed record.
     *
     * @return array{0: string, 1: ?string, 2: array{initials: string, photo: ?string}|null}
     */
    private static function describe(string $type, Model $model): array
    {
        return match ($type) {
            'user' => [
                $model->full_name,
                $model->email,
                ['initials' => self::initials($model->first_name, $model->last_name), 'photo' => $model->avatar],
            ],
            'employee' => [
                $model->full_name,
                $model->employee_no,
                ['initials' => self::initials($model->first_name, $model->last_name), 'photo' => $model->photo_url],
            ],
            'department' => [$model->name, $model->code, null],
            'leave_type' => [$model->name, $model->code, null],
            default => [class_basename($model), null, null],
        };
    }

    private static function initials(?string $first, ?string $last): string
    {
        return mb_strtoupper(mb_substr((string) $first, 0, 1).mb_substr((string) $last, 0, 1));
    }
}
